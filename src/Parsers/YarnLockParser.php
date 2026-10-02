<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Parsers\Concerns\DerivesFlagsFromGraph;

/**
 * Parses yarn.lock v1. Every block is one installed version; its comma-separated
 * descriptors (`name@range`) are what other blocks resolve their requirements through.
 *
 * @phpstan-import-type ParsedPackage from LockfileParser
 * @phpstan-import-type ParsedDependency from LockfileParser
 */
class YarnLockParser implements LockfileParser
{
    use DerivesFlagsFromGraph;

    /**
     * @param  string  $content  raw yarn.lock
     * @param  string|null  $manifestContent  raw package.json
     * @return array<int, ParsedPackage>
     *
     * @throws UnsupportedLockfileException for a Yarn Berry (v2+) lockfile
     */
    public function parse(string $content, ?string $manifestContent = null): array
    {
        if (preg_match('/^__metadata:/m', $content) === 1) {
            throw new UnsupportedLockfileException(SyncWarning::YarnBerryUnsupported);
        }

        $blocks = $this->parseBlocks($content);
        $descriptorKeys = $this->descriptorKeys($blocks);
        $packages = [];

        foreach ($blocks as $block) {
            $key = $block['descriptors'][0];
            $name = $this->extractName($key);

            if ($name === null) {
                continue;
            }

            $packages[] = [
                'key' => $key,
                'name' => $name,
                'version' => $block['version'] ?? 'unknown',
                'type' => PackageType::Npm,
                'is_direct' => false,
                'is_dev' => false,
                'dependencies' => $this->dependencies($block['dependencies'], $descriptorKeys),
            ];
        }

        $manifest = $manifestContent !== null ? json_decode($manifestContent, true) : null;

        if (! is_array($manifest)) {
            return $this->markUnrequiredPackagesDirect($packages);
        }

        return $this->propagateDevFlag($this->markManifestPackagesDirect($packages, $manifest, $descriptorKeys));
    }

    /**
     * @param  array<int, array{descriptors: array<int, string>, version: ?string, dependencies: array<int, array{name: string, range: string, kind: DependencyKind}>}>  $blocks
     * @return array<string, string> descriptor => key of the block it belongs to
     */
    private function descriptorKeys(array $blocks): array
    {
        $descriptorKeys = [];

        foreach ($blocks as $block) {
            foreach ($block['descriptors'] as $descriptor) {
                $descriptorKeys[$descriptor] = $block['descriptors'][0];
            }
        }

        return $descriptorKeys;
    }

    /**
     * @param  array<int, array{name: string, range: string, kind: DependencyKind}>  $requirements
     * @param  array<string, string>  $descriptorKeys
     * @return array<int, ParsedDependency>
     */
    private function dependencies(array $requirements, array $descriptorKeys): array
    {
        $dependencies = [];

        foreach ($requirements as $requirement) {
            $key = $descriptorKeys[$requirement['name'] . '@' . $requirement['range']] ?? null;

            if ($key !== null) {
                $dependencies[$key] ??= ['key' => $key, 'constraint' => $requirement['range'], 'kind' => $requirement['kind']];
            }
        }

        return array_values($dependencies);
    }

    /**
     * @param  array<int, ParsedPackage>  $packages
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $descriptorKeys
     * @return array<int, ParsedPackage>
     */
    private function markManifestPackagesDirect(array $packages, array $manifest, array $descriptorKeys): array
    {
        $directKeys = [];

        foreach (['dependencies' => false, 'optionalDependencies' => false, 'devDependencies' => true] as $section => $isDev) {
            foreach ($manifest[$section] ?? [] as $name => $range) {
                $key = $descriptorKeys[$name . '@' . $range] ?? null;

                if ($key !== null) {
                    $directKeys[$key] = ($directKeys[$key] ?? true) && $isDev;
                }
            }
        }

        foreach ($packages as $index => $package) {
            $packages[$index]['is_direct'] = isset($directKeys[$package['key']]);
            $packages[$index]['is_dev'] = $directKeys[$package['key']] ?? false;
        }

        return $packages;
    }

    /**
     * @return array<int, array{descriptors: array<int, string>, version: ?string, dependencies: array<int, array{name: string, range: string, kind: DependencyKind}>}>
     */
    private function parseBlocks(string $content): array
    {
        // Normalise line endings so CRLF (e.g. a Windows-generated yarn.lock or a
        // fixture checked out on Windows) parses identically to LF.
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $blocks = [];
        $current = null;
        $dependencyKind = null;

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }

            // New block: unindented line ending with ':'
            if (! str_starts_with($line, ' ') && str_ends_with(rtrim($line), ':')) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = [
                    'descriptors' => $this->parseDescriptors(rtrim(rtrim($line), ':')),
                    'version' => null,
                    'dependencies' => [],
                ];
                $dependencyKind = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^ {2}(\S.*)$/', $line, $matches) === 1) {
                $field = $matches[1];
                $dependencyKind = match ($field) {
                    'dependencies:' => DependencyKind::Dependency,
                    'optionalDependencies:' => DependencyKind::Optional,
                    default => null,
                };

                if (preg_match('/^version\s+"(.+)"$/', $field, $versionMatch) === 1) {
                    $current['version'] = $versionMatch[1];
                }

                continue;
            }

            if ($dependencyKind !== null && preg_match('/^ {4}"?([^"\s]+)"?\s+"?([^"]*)"?$/', $line, $matches) === 1) {
                $current['dependencies'][] = ['name' => $matches[1], 'range' => $matches[2], 'kind' => $dependencyKind];
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * `"ms@2.1.2", ms@^2.1.1` → ['ms@2.1.2', 'ms@^2.1.1']
     *
     * @return array<int, string>
     */
    private function parseDescriptors(string $header): array
    {
        return array_map(fn (string $descriptor): string => trim($descriptor, ' "'), explode(',', $header));
    }

    /**
     * Extract the package name from a descriptor.
     *
     * Examples:
     *   "lodash@^4.17.0"        -> lodash
     *   "@scope/pkg@^1.0.0"     -> @scope/pkg
     */
    private function extractName(string $descriptor): ?string
    {
        $atPos = strpos($descriptor, '@', str_starts_with($descriptor, '@') ? 1 : 0);

        return $atPos === false ? null : substr($descriptor, 0, $atPos);
    }
}
