<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Parsers\Concerns\DerivesFlagsFromGraph;

/**
 * Parses Bun's text lockfile (bun.lock, Bun 1.2+): JSON with trailing commas.
 * Every `packages` entry is one installed package keyed by its install path
 * (`debug/ms` is the `ms` nested under `debug`), and dependencies resolve the
 * way node_modules lookup does: nearest copy first, then up the path. Workspaces
 * declare their dependencies, dev dependencies and optional dependencies apart,
 * so no package.json is needed.
 *
 * @phpstan-import-type ParsedPackage from LockfileParser
 * @phpstan-import-type ParsedDependency from LockfileParser
 *
 * @phpstan-type BunNode array{name: string, version: string, metadata: array<string, mixed>}
 */
class BunLockParser implements LockfileParser
{
    use DerivesFlagsFromGraph;

    /**
     * Requirement maps in a package's metadata; the first edge to a key wins.
     *
     * @var array<string, DependencyKind>
     */
    private const array DEPENDENCY_FIELDS = [
        'dependencies' => DependencyKind::Dependency,
        'optionalDependencies' => DependencyKind::Optional,
        'peerDependencies' => DependencyKind::Peer,
    ];

    /**
     * Local directories rather than installed packages.
     *
     * @var array<int, string>
     */
    private const array LOCAL_PROTOCOLS = ['workspace:', 'link:', 'file:'];

    /**
     * @param  string  $content  raw bun.lock
     * @param  string|null  $manifestContent  unused: workspaces are in the lockfile
     * @return array<int, ParsedPackage>
     *
     * @throws UnsupportedLockfileException when the content is not a readable bun.lock
     */
    public function parse(string $content, ?string $manifestContent = null): array
    {
        $document = $this->readDocument($content);
        $nodes = $this->nodes($document['packages']);
        $packages = [];

        foreach ($nodes as $key => $node) {
            $packages[] = [
                'key' => (string) $key,
                'name' => $node['name'],
                'version' => $node['version'],
                'type' => PackageType::Npm,
                'is_direct' => false,
                'is_dev' => false,
                'dependencies' => $this->dependencies((string) $key, $node['metadata'], $nodes),
            ];
        }

        $workspaces = $this->mapField($document, 'workspaces');

        if ($workspaces === []) {
            return $this->markUnrequiredPackagesDirect($packages);
        }

        $directKeys = $this->directKeys($workspaces, $nodes);

        foreach ($packages as $index => $package) {
            $packages[$index]['is_direct'] = isset($directKeys[$package['key']]);
            $packages[$index]['is_dev'] = $directKeys[$package['key']] ?? false;
        }

        return $this->keepProductionReachableRootsProduction($this->propagateDevFlag($packages));
    }

    /**
     * @return array{packages: array<array-key, mixed>, workspaces?: mixed}
     *
     * @throws UnsupportedLockfileException
     */
    private function readDocument(string $content): array
    {
        $json = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|,(?=\s*[}\]])/',
            fn (array $match): string => $match[0][0] === '"' ? $match[0] : '',
            $content,
        );
        $document = $json === null ? null : json_decode($json, true);

        if (! is_array($document) || ! is_array($document['packages'] ?? null)) {
            throw new UnsupportedLockfileException(SyncWarning::LockfileUnreadable);
        }

        return $document;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, BunNode> install path => node
     */
    private function nodes(array $entries): array
    {
        $nodes = [];

        foreach ($entries as $key => $entry) {
            if (! is_array($entry) || ! is_string($entry[0] ?? null)) {
                continue;
            }

            [$name, $reference] = $this->splitLocator($entry[0]);

            if ($this->isLocal($reference)) {
                continue;
            }

            $nodes[(string) $key] = [
                'name' => $name,
                'version' => $reference === '' ? 'unknown' : preg_replace('/^npm:/', '', $reference),
                'metadata' => $this->metadata($entry),
            ];
        }

        return $nodes;
    }

    /**
     * The first object after the locator: index 2 for registry packages, 1 for git ones.
     *
     * @param  array<int, mixed>  $entry
     * @return array<string, mixed>
     */
    private function metadata(array $entry): array
    {
        foreach (array_slice($entry, 1) as $element) {
            if (is_array($element)) {
                return $element;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, BunNode>  $nodes
     * @return array<int, ParsedDependency>
     */
    private function dependencies(string $fromKey, array $metadata, array $nodes): array
    {
        $dependencies = [];

        foreach (self::DEPENDENCY_FIELDS as $field => $kind) {
            foreach ($this->mapField($metadata, $field) as $name => $constraint) {
                $key = $this->resolve($fromKey, (string) $name, $nodes);

                if ($key !== null) {
                    $dependencies[$key] ??= ['key' => $key, 'constraint' => (string) $constraint, 'kind' => $kind];
                }
            }
        }

        return array_values($dependencies);
    }

    /**
     * node_modules lookup: `a/b` requiring `c` tries `a/b/c`, `a/c`, then `c`.
     *
     * @param  array<string, BunNode>  $nodes
     */
    private function resolve(string $fromKey, string $name, array $nodes): ?string
    {
        $segments = $this->pathSegments($fromKey);

        for ($depth = count($segments); $depth >= 0; $depth--) {
            $candidate = implode('/', [...array_slice($segments, 0, $depth), $name]);

            if (isset($nodes[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The installed packages each workspace declares, and whether every workspace
     * that declares one lists it only under devDependencies.
     *
     * @param  array<array-key, mixed>  $workspaces  path => workspace manifest
     * @param  array<string, BunNode>  $nodes
     * @return array<string, bool> key => is dev
     */
    private function directKeys(array $workspaces, array $nodes): array
    {
        $directKeys = [];

        foreach ($workspaces as $path => $workspace) {
            if (! is_array($workspace)) {
                continue;
            }

            $fromKey = $path === '' ? '' : (string) ($workspace['name'] ?? $path);
            $requirements = array_replace(
                array_fill_keys(array_keys($this->mapField($workspace, 'devDependencies')), true),
                array_fill_keys(array_keys($this->mapField($workspace, 'optionalDependencies')), false),
                array_fill_keys(array_keys($this->mapField($workspace, 'dependencies')), false),
            );

            foreach ($requirements as $name => $isDev) {
                $key = $this->resolve($fromKey, (string) $name, $nodes);

                if ($key !== null) {
                    $directKeys[$key] = ($directKeys[$key] ?? true) && $isDev;
                }
            }
        }

        return $directKeys;
    }

    /**
     * A dev dependency that a production package also requires is installed for
     * production too, so it must not be hidden as dev.
     *
     * @param  array<int, ParsedPackage>  $packages
     * @return array<int, ParsedPackage>
     */
    private function keepProductionReachableRootsProduction(array $packages): array
    {
        $dependencyKeys = [];
        $productionRoots = [];

        foreach ($packages as $package) {
            $dependencyKeys[$package['key']] = array_column($package['dependencies'], 'key');

            if ($package['is_direct'] && ! $package['is_dev']) {
                $productionRoots[] = $package['key'];
            }
        }

        $reachableFromProduction = $this->reachableKeys($productionRoots, $dependencyKeys);

        foreach ($packages as $index => $package) {
            if ($package['is_direct'] && isset($reachableFromProduction[$package['key']])) {
                $packages[$index]['is_dev'] = false;
            }
        }

        return $packages;
    }

    /**
     * `@scope/a/b` → ['@scope/a', 'b']
     *
     * @return array<int, string>
     */
    private function pathSegments(string $key): array
    {
        if ($key === '') {
            return [];
        }

        $segments = [];
        $parts = explode('/', $key);

        while ($parts !== []) {
            $part = array_shift($parts);

            if (str_starts_with($part, '@') && $parts !== []) {
                $part .= '/' . array_shift($parts);
            }

            $segments[] = $part;
        }

        return $segments;
    }

    private function isLocal(string $reference): bool
    {
        foreach (self::LOCAL_PROTOCOLS as $protocol) {
            if (str_starts_with($reference, $protocol)) {
                return true;
            }
        }

        return false;
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
     * `@scope/pkg@1.0.0` → ['@scope/pkg', '1.0.0']
     *
     * @return array{0: string, 1: string}
     */
    private function splitLocator(string $locator): array
    {
        $atPos = strpos($locator, '@', str_starts_with($locator, '@') ? 1 : 0);

        return $atPos === false ? [$locator, ''] : [substr($locator, 0, $atPos), substr($locator, $atPos + 1)];
    }
}
