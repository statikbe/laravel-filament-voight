<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Parsers\Concerns\DerivesFlagsFromGraph;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a Yarn Berry (v2+) yarn.lock. Every hard-linked entry is one installed
 * package keyed by its `resolution`; its comma-separated descriptors
 * (`name@npm:^1.0.0`) are what other entries resolve their dependencies through.
 * Workspaces are entries too, and their dependencies are the direct packages.
 *
 * @phpstan-import-type ParsedPackage from LockfileParser
 * @phpstan-import-type ParsedDependency from LockfileParser
 *
 * @phpstan-type BerryRequirement array{name: string, range: string, kind: DependencyKind}
 * @phpstan-type BerryEntry array{descriptors: array<int, string>, resolution: string, name: string, version: string, protocol: string, isHard: bool, dependencies: array<int, BerryRequirement>}
 */
class YarnBerryLockParser implements LockfileParser
{
    use DerivesFlagsFromGraph;

    private const string ROOT_WORKSPACE_SUFFIX = '@workspace:.';

    public static function detects(string $content): bool
    {
        return preg_match('/^__metadata:/m', $content) === 1;
    }

    /**
     * @param  string  $content  raw yarn.lock
     * @param  string|null  $manifestContent  raw package.json of the root workspace
     * @return array<int, ParsedPackage>
     *
     * @throws UnsupportedLockfileException when the content is not valid YAML
     */
    public function parse(string $content, ?string $manifestContent = null): array
    {
        $entries = $this->readEntries($content);
        $descriptorResolutions = $this->descriptorResolutions($entries);
        $nodeEntries = array_filter($entries, $this->isNode(...));
        $packages = [];

        foreach ($nodeEntries as $entry) {
            $packages[] = [
                'key' => $entry['resolution'],
                'name' => $entry['name'],
                'version' => $entry['version'],
                'type' => PackageType::Npm,
                'is_direct' => false,
                'is_dev' => false,
                'dependencies' => $this->dependencies($entry['dependencies'], $descriptorResolutions, $nodeEntries),
            ];
        }

        $workspaces = array_filter($entries, fn (array $entry): bool => $entry['protocol'] === 'workspace');

        if ($workspaces === []) {
            return $this->markUnrequiredPackagesDirect($packages);
        }

        $manifest = $manifestContent !== null ? json_decode($manifestContent, true) : null;
        $directKeys = $this->directKeys($workspaces, is_array($manifest) ? $manifest : [], $descriptorResolutions, $nodeEntries);

        foreach ($packages as $index => $package) {
            $packages[$index]['is_direct'] = isset($directKeys[$package['key']]);
            $packages[$index]['is_dev'] = $directKeys[$package['key']] ?? false;
        }

        return $this->propagateDevFlag($packages);
    }

    /**
     * @return array<string, BerryEntry> resolution => entry
     *
     * @throws UnsupportedLockfileException
     */
    private function readEntries(string $content): array
    {
        try {
            $document = Yaml::parse($content);
        } catch (ParseException) {
            throw new UnsupportedLockfileException(SyncWarning::LockfileUnreadable);
        }

        if (! is_array($document)) {
            throw new UnsupportedLockfileException(SyncWarning::LockfileUnreadable);
        }

        $entries = [];

        foreach ($document as $header => $fields) {
            if ($header === '__metadata' || ! is_array($fields) || ! is_string($fields['resolution'] ?? null)) {
                continue;
            }

            $resolution = $fields['resolution'];
            [$name, $reference] = $this->splitDescriptor($resolution);

            $entries[$resolution] = [
                'descriptors' => array_map(trim(...), explode(',', (string) $header)),
                'resolution' => $resolution,
                'name' => $name,
                'version' => is_string($fields['version'] ?? null) ? $fields['version'] : $this->referenceVersion($reference),
                'protocol' => $this->protocol($reference) ?? 'npm',
                'isHard' => ($fields['linkType'] ?? 'hard') === 'hard',
                'dependencies' => $this->requirements($fields),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<int, BerryRequirement>
     */
    private function requirements(array $fields): array
    {
        $requirements = [];

        foreach ($this->mapField($fields, 'dependencies') as $name => $range) {
            $isOptional = ($fields['dependenciesMeta'][$name]['optional'] ?? false) === true;

            $requirements[] = [
                'name' => (string) $name,
                'range' => (string) $range,
                'kind' => $isOptional ? DependencyKind::Optional : DependencyKind::Dependency,
            ];
        }

        return $requirements;
    }

    /**
     * @param  array<string, BerryEntry>  $entries
     * @return array<string, string> descriptor => resolution of the entry it belongs to
     */
    private function descriptorResolutions(array $entries): array
    {
        $descriptorResolutions = [];

        foreach ($entries as $entry) {
            foreach ($entry['descriptors'] as $descriptor) {
                $descriptorResolutions[$descriptor] = $entry['resolution'];
            }
        }

        return $descriptorResolutions;
    }

    /**
     * @param  array<int, BerryRequirement>  $requirements
     * @param  array<string, string>  $descriptorResolutions
     * @param  array<string, BerryEntry>  $nodeEntries
     * @return array<int, ParsedDependency>
     */
    private function dependencies(array $requirements, array $descriptorResolutions, array $nodeEntries): array
    {
        $dependencies = [];

        foreach ($requirements as $requirement) {
            $key = $this->resolve($requirement['name'], $requirement['range'], $descriptorResolutions, $nodeEntries);

            if ($key !== null) {
                $dependencies[$key] ??= ['key' => $key, 'constraint' => $requirement['range'], 'kind' => $requirement['kind']];
            }
        }

        return array_values($dependencies);
    }

    /**
     * The node a dependency resolves to. A patched dependency resolves to the
     * package it wraps (one level deep); soft links and workspaces are no node.
     *
     * @param  array<string, string>  $descriptorResolutions
     * @param  array<string, BerryEntry>  $nodeEntries
     */
    private function resolve(string $name, string $range, array $descriptorResolutions, array $nodeEntries): ?string
    {
        $range = $this->normaliseRange($range);

        if (str_starts_with($range, 'patch:')) {
            [$name, $range] = $this->splitDescriptor(rawurldecode(explode('#', substr($range, strlen('patch:')), 2)[0]));
            $range = $this->normaliseRange($range);

            if (str_starts_with($range, 'patch:')) {
                return null;
            }
        }

        $resolution = $descriptorResolutions[$name . '@' . $range] ?? null;

        return $resolution !== null && isset($nodeEntries[$resolution]) ? $resolution : null;
    }

    /**
     * The installed packages each workspace declares, and whether only the root
     * declares it as a dev dependency. The lockfile merges dev dependencies into
     * `dependencies`, so dev comes from the root package.json; member workspaces'
     * manifests are not uploaded, so their dependencies count as production.
     *
     * @param  array<string, BerryEntry>  $workspaces
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $descriptorResolutions
     * @param  array<string, BerryEntry>  $nodeEntries
     * @return array<string, bool> key => is dev
     */
    private function directKeys(array $workspaces, array $manifest, array $descriptorResolutions, array $nodeEntries): array
    {
        $devNames = array_diff_key(
            $this->mapField($manifest, 'devDependencies'),
            $this->mapField($manifest, 'dependencies'),
            $this->mapField($manifest, 'optionalDependencies'),
        );
        $directKeys = [];

        foreach ($workspaces as $workspace) {
            $isRoot = str_ends_with($workspace['resolution'], self::ROOT_WORKSPACE_SUFFIX);

            foreach ($workspace['dependencies'] as $requirement) {
                $key = $this->resolve($requirement['name'], $requirement['range'], $descriptorResolutions, $nodeEntries);

                if ($key !== null) {
                    $isDev = $isRoot && isset($devNames[$requirement['name']]);
                    $directKeys[$key] = ($directKeys[$key] ?? true) && $isDev;
                }
            }
        }

        return $directKeys;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed> the field when it is a map, otherwise empty
     */
    private function mapField(array $fields, string $field): array
    {
        return is_array($fields[$field] ?? null) ? $fields[$field] : [];
    }

    /**
     * @param  BerryEntry  $entry
     */
    private function isNode(array $entry): bool
    {
        return $entry['isHard'] && $entry['protocol'] !== 'patch';
    }

    /**
     * Yarn 2/3 write dependency ranges without the default `npm:` protocol that
     * their descriptors carry.
     */
    private function normaliseRange(string $range): string
    {
        return $this->protocol($range) === null ? 'npm:' . $range : $range;
    }

    private function protocol(string $reference): ?string
    {
        return preg_match('/^([a-z][a-z0-9+.-]*):/i', $reference, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * `npm:1.10` → `1.10`, for a `version` that YAML read as a number.
     */
    private function referenceVersion(string $reference): string
    {
        $version = preg_replace('/^[a-z][a-z0-9+.-]*:/i', '', $reference);

        return $version === '' || $version === null ? 'unknown' : $version;
    }

    /**
     * `@scope/pkg@npm:^1.0.0` → ['@scope/pkg', 'npm:^1.0.0']
     *
     * @return array{0: string, 1: string}
     */
    private function splitDescriptor(string $descriptor): array
    {
        $atPos = strpos($descriptor, '@', str_starts_with($descriptor, '@') ? 1 : 0);

        return $atPos === false ? [$descriptor, ''] : [substr($descriptor, 0, $atPos), substr($descriptor, $atPos + 1)];
    }
}
