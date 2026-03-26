<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\PackageType;

class PackageLockParser
{
    /**
     * @param  string  $content  Raw package-lock.json content
     * @return array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>
     */
    public function parse(string $content): array
    {
        $lock = json_decode($content, true);

        if (! is_array($lock)) {
            return [];
        }

        $packages = [];
        $lockfilePackages = $lock['packages'] ?? [];

        // Top-level dependencies from package.json references
        $topLevelDeps = array_keys($lock['dependencies'] ?? []);
        $topLevelDevDeps = array_keys($lock['devDependencies'] ?? []);

        foreach ($lockfilePackages as $path => $packageData) {
            // Skip the root project entry
            if ($path === '') {
                continue;
            }

            // Extract package name from path (e.g. "node_modules/lodash" → "lodash")
            $name = preg_replace('#^node_modules/#', '', $path);

            // Skip nested node_modules (transitive duplicates at different versions)
            if (str_contains($name, 'node_modules/')) {
                continue;
            }

            $isDirect = in_array($name, $topLevelDeps) || in_array($name, $topLevelDevDeps);
            $isDev = $packageData['dev'] ?? false;

            $packages[] = [
                'name' => $name,
                'version' => ltrim($packageData['version'] ?? 'unknown', 'v'),
                'type' => PackageType::Npm,
                'is_direct' => $isDirect,
                'is_dev' => (bool) $isDev,
                'require' => array_keys($packageData['dependencies'] ?? []),
            ];
        }

        return $packages;
    }
}
