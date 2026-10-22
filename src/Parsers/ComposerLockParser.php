<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Parsers\Concerns\DerivesFlagsFromGraph;

/**
 * @phpstan-import-type ParsedPackage from LockfileParser
 * @phpstan-import-type ParsedDependency from LockfileParser
 */
class ComposerLockParser implements LockfileParser
{
    use DerivesFlagsFromGraph;

    private const string PLATFORM_REQUIREMENT_PATTERN = '/^(php(-64bit)?|hhvm|ext-.+|lib-.+|composer(-plugin-api|-runtime-api)?)$/';

    /**
     * Lowercased real package name => key.
     *
     * @var array<string, string>
     */
    private array $realPackages = [];

    /**
     * Lowercased replaced or provided name => keys of the packages that replace or provide it.
     *
     * @var array<string, array<int, string>>
     */
    private array $virtualPackages = [];

    /**
     * @param  string  $content  raw composer.lock
     * @param  string|null  $manifestContent  raw composer.json
     * @return array<int, ParsedPackage>
     */
    public function parse(string $content, ?string $manifestContent = null): array
    {
        $lock = json_decode($content, true);

        if (! is_array($lock)) {
            return [];
        }

        /** @var array<int, array{0: array<string, mixed>, 1: bool}> $entries */
        $entries = [];

        foreach (['packages' => false, 'packages-dev' => true] as $section => $isDev) {
            foreach ($lock[$section] ?? [] as $entry) {
                $entries[] = [$entry, $isDev];
            }
        }

        $this->buildNameIndex(array_column($entries, 0));

        $packages = [];

        foreach ($entries as [$entry, $isDev]) {
            $packages[] = [
                'key' => strtolower($entry['name']),
                'name' => $entry['name'],
                'version' => ltrim($entry['version'] ?? 'unknown', 'v'),
                'type' => PackageType::Composer,
                'is_direct' => false,
                'is_dev' => $isDev,
                'dependencies' => $this->dependencies($entry),
            ];
        }

        $manifest = $manifestContent !== null ? json_decode($manifestContent, true) : null;

        return is_array($manifest)
            ? $this->markManifestPackagesDirect($packages, $manifest)
            : $this->markUnrequiredPackagesDirect($packages);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function buildNameIndex(array $entries): void
    {
        $this->realPackages = [];
        $this->virtualPackages = [];

        foreach ($entries as $entry) {
            $key = strtolower($entry['name']);
            $this->realPackages[$key] = $key;

            foreach ([...array_keys($entry['replace'] ?? []), ...array_keys($entry['provide'] ?? [])] as $virtualName) {
                $this->virtualPackages[strtolower($virtualName)][] = $key;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolve(string $name): array
    {
        $name = strtolower($name);

        if (isset($this->realPackages[$name])) {
            return [$this->realPackages[$name]];
        }

        return array_values(array_unique($this->virtualPackages[$name] ?? []));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, ParsedDependency>
     */
    private function dependencies(array $entry): array
    {
        $ownKey = strtolower($entry['name']);
        $dependencies = [];

        foreach ($entry['require'] ?? [] as $name => $constraint) {
            if ($this->isPlatformRequirement($name)) {
                continue;
            }

            foreach ($this->resolve($name) as $key) {
                if ($key !== $ownKey && ! isset($dependencies[$key])) {
                    $dependencies[$key] = ['key' => $key, 'constraint' => (string) $constraint, 'kind' => DependencyKind::Dependency];
                }
            }
        }

        return array_values($dependencies);
    }

    /**
     * @param  array<int, ParsedPackage>  $packages
     * @param  array<string, mixed>  $manifest
     * @return array<int, ParsedPackage>
     */
    private function markManifestPackagesDirect(array $packages, array $manifest): array
    {
        $directKeys = [];

        foreach ([...array_keys($manifest['require'] ?? []), ...array_keys($manifest['require-dev'] ?? [])] as $name) {
            if (! $this->isPlatformRequirement($name)) {
                foreach ($this->resolve($name) as $key) {
                    $directKeys[$key] = true;
                }
            }
        }

        foreach ($packages as $index => $package) {
            $packages[$index]['is_direct'] = isset($directKeys[$package['key']]);
        }

        return $packages;
    }

    private function isPlatformRequirement(string $name): bool
    {
        return preg_match(self::PLATFORM_REQUIREMENT_PATTERN, strtolower($name)) === 1;
    }
}
