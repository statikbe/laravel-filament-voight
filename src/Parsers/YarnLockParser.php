<?php

namespace Statikbe\FilamentVoight\Parsers;

use Statikbe\FilamentVoight\Enums\PackageType;

class YarnLockParser
{
    /**
     * @param  string  $content  Raw yarn.lock content
     * @param  string|null  $packageJsonContent  Raw package.json content for is_dev/is_direct detection
     * @return array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>
     */
    public function parse(string $content, ?string $packageJsonContent = null): array
    {
        $blocks = $this->parseBlocks($content);
        [$directDeps, $directDevDeps] = $this->parsePackageJson($packageJsonContent);
        $hasPackageJson = $packageJsonContent !== null;

        $packages = [];

        foreach ($blocks as $block) {
            $name = $this->extractName($block['descriptor']);

            if ($name === null) {
                continue;
            }

            $isDirect = $hasPackageJson
                ? in_array($name, $directDeps) || in_array($name, $directDevDeps)
                : true;

            $isDev = $hasPackageJson
                ? in_array($name, $directDevDeps)
                : false;

            $packages[] = [
                'name' => $name,
                'version' => $block['version'] ?? 'unknown',
                'type' => PackageType::Npm,
                'is_direct' => $isDirect,
                'is_dev' => $isDev,
                'require' => $block['dependencies'],
            ];
        }

        return $packages;
    }

    /**
     * @return array{0: array<string>, 1: array<string>}
     */
    private function parsePackageJson(?string $packageJsonContent): array
    {
        if ($packageJsonContent === null) {
            return [[], []];
        }

        $packageJson = json_decode($packageJsonContent, true);

        if (! is_array($packageJson)) {
            return [[], []];
        }

        return [
            array_keys($packageJson['dependencies'] ?? []),
            array_keys($packageJson['devDependencies'] ?? []),
        ];
    }

    /**
     * @return array<int, array{descriptor: string, version: ?string, dependencies: array<string>}>
     */
    private function parseBlocks(string $content): array
    {
        $lines = explode("\n", $content);
        $blocks = [];
        $current = null;
        $inDependencies = false;

        foreach ($lines as $line) {
            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // New block: line not starting with whitespace and ending with ':'
            if (! str_starts_with($line, ' ') && str_ends_with(rtrim($line), ':')) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = [
                    'descriptor' => rtrim($line, ':'),
                    'version' => null,
                    'dependencies' => [],
                ];
                $inDependencies = false;

                continue;
            }

            if ($current === null) {
                continue;
            }

            $trimmed = trim($line);

            // Check for dependencies/optionalDependencies section header
            if ($trimmed === 'dependencies:' || $trimmed === 'optionalDependencies:') {
                $inDependencies = true;

                continue;
            }

            // Any other non-indented-deeper section header ends dependencies
            if (preg_match('/^\s{2}\w/', $line) && str_ends_with($trimmed, ':') && ! str_contains($trimmed, '"')) {
                $inDependencies = false;
            }

            // Parse version
            if (preg_match('/^\s+version\s+"(.+)"$/', $line, $matches)) {
                $current['version'] = $matches[1];
                $inDependencies = false;

                continue;
            }

            // Parse dependency entries (indented with 4 spaces under dependencies:)
            if ($inDependencies && preg_match('/^\s{4}"?([^"]+)"?\s+"/', $line, $matches)) {
                $current['dependencies'][] = $matches[1];
            }
        }

        // Don't forget the last block
        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Extract the package name from a yarn.lock descriptor line.
     *
     * Examples:
     *   lodash@^4.17.0          -> lodash
     *   "lodash@^4.17.0"        -> lodash
     *   "@scope/pkg@^1.0.0"     -> @scope/pkg
     *   "pkg@^1.0.0", "pkg@^2.0.0" -> pkg
     */
    private function extractName(string $descriptor): ?string
    {
        // Take the first entry if multiple ranges are listed (comma-separated)
        $first = explode(',', $descriptor)[0];
        $first = trim($first, ' "');

        // For scoped packages (@scope/name@version), find the last '@' after the scope
        if (str_starts_with($first, '@')) {
            // Find the second '@' which separates name from version range
            $atPos = strpos($first, '@', 1);

            if ($atPos === false) {
                return null;
            }

            return substr($first, 0, $atPos);
        }

        // For regular packages (name@version)
        $atPos = strpos($first, '@');

        if ($atPos === false) {
            return null;
        }

        return substr($first, 0, $atPos);
    }
}
