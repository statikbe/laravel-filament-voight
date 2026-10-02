<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;

/**
 * Parses package-lock.json v2 and v3. Every entry under a node_modules path is an
 * installed node, including nested copies at other versions.
 *
 * @phpstan-import-type ParsedPackage from LockfileParser
 * @phpstan-import-type ParsedDependency from LockfileParser
 */
class PackageLockParser implements LockfileParser
{
    /**
     * Dependency maps in order of precedence: a name listed in several keeps the first kind.
     *
     * @var array<string, DependencyKind>
     */
    private const array DEPENDENCY_MAPS = [
        'dependencies' => DependencyKind::Dependency,
        'optionalDependencies' => DependencyKind::Optional,
        'peerDependencies' => DependencyKind::Peer,
    ];

    /**
     * Maps whose packages the root project or a workspace member declares itself.
     *
     * @var array<int, string>
     */
    private const array DECLARATION_MAPS = ['dependencies', 'devDependencies', 'optionalDependencies'];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $entries = [];

    /**
     * The manifest is not needed: `packages[""]` holds the root's declarations.
     *
     * @param  string  $content  raw package-lock.json
     * @return array<int, ParsedPackage>
     *
     * @throws UnsupportedLockfileException for a lockfile v1 (no `packages` key)
     */
    public function parse(string $content, ?string $manifestContent = null): array
    {
        $lock = json_decode($content, true);

        if (! is_array($lock)) {
            return [];
        }

        if (! array_key_exists('packages', $lock)) {
            throw new UnsupportedLockfileException(SyncWarning::NpmLockfileV1Unsupported);
        }

        $this->entries = $lock['packages'];
        $directKeys = $this->directKeys();
        $packages = [];

        foreach ($this->entries as $path => $entry) {
            $path = (string) $path;

            if (! $this->isInstalledNode($path)) {
                continue;
            }

            $packages[] = [
                'key' => $path,
                'name' => $entry['name'] ?? $this->pathSegmentName($path),
                'version' => ltrim($entry['version'] ?? 'unknown', 'v'),
                'type' => PackageType::Npm,
                'is_direct' => isset($directKeys[$path]),
                'is_dev' => ($entry['dev'] ?? false) || ($entry['devOptional'] ?? false),
                'dependencies' => $this->dependencies($path, $entry),
            ];
        }

        return $packages;
    }

    /**
     * The nodes the root project and its workspace members resolve their own declarations to.
     *
     * @return array<string, true>
     */
    private function directKeys(): array
    {
        $directKeys = [];

        foreach ($this->entries as $path => $entry) {
            $path = (string) $path;

            if ($this->isInstalledNode($path) || ($entry['link'] ?? false)) {
                continue;
            }

            foreach (self::DECLARATION_MAPS as $map) {
                foreach (array_keys($entry[$map] ?? []) as $name) {
                    $key = $this->resolve($path, (string) $name);

                    if ($key !== null) {
                        $directKeys[$key] = true;
                    }
                }
            }
        }

        return $directKeys;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, ParsedDependency>
     */
    private function dependencies(string $path, array $entry): array
    {
        $dependencies = [];

        foreach (self::DEPENDENCY_MAPS as $map => $kind) {
            foreach ($entry[$map] ?? [] as $name => $constraint) {
                $key = $this->resolve($path, (string) $name);

                if ($key === null) {
                    continue;
                }

                $dependencies[$key] ??= ['key' => $key, 'constraint' => (string) $constraint, 'kind' => $kind];
            }
        }

        return array_values($dependencies);
    }

    /**
     * Node's resolution rule: look in the requirer's own node_modules, then walk up
     * one node_modules level at a time, ending at the project root.
     */
    private function resolve(string $fromPath, string $name): ?string
    {
        $base = $fromPath;

        while (true) {
            $candidate = ($base === '' ? '' : $base . '/') . 'node_modules/' . $name;
            $entry = $this->entries[$candidate] ?? null;

            if ($entry !== null) {
                return ($entry['link'] ?? false) ? null : $candidate;
            }

            if ($base === '') {
                return null;
            }

            $nestedAt = strrpos($base, '/node_modules/');
            $base = $nestedAt === false ? '' : substr($base, 0, $nestedAt);
        }
    }

    private function isInstalledNode(string $path): bool
    {
        if (! str_starts_with($path, 'node_modules/') && ! str_contains($path, '/node_modules/')) {
            return false;
        }

        return ! ($this->entries[$path]['link'] ?? false);
    }

    private function pathSegmentName(string $path): string
    {
        return substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
    }
}
