<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\PackageType;

class ComposerLockParser
{
    /**
     * @param string $content Raw composer.lock JSON content
     * @return array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>
     */
    public function parse(string $content): array
    {
        $lock = json_decode($content, true);

        if (! is_array($lock)) {
            return [];
        }

        $packages = [];

        // composer.lock doesn't tell us directly which are top-level.
        // We mark all as is_direct=true initially; the job can refine this
        // using the composer.json if provided in the future.
        foreach (['packages' => false, 'packages-dev' => true] as $key => $isDev) {
            foreach ($lock[$key] ?? [] as $package) {
                $packages[] = [
                    'name' => $package['name'],
                    'version' => ltrim($package['version'] ?? 'unknown', 'v'),
                    'type' => PackageType::Composer,
                    'is_direct' => true,
                    'is_dev' => $isDev,
                    'require' => array_keys($package['require'] ?? []),
                ];
            }
        }

        return $packages;
    }
}
