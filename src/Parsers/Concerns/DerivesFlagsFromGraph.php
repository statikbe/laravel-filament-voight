<?php

namespace Statikbe\FilamentVoight\Parsers\Concerns;

use Statikbe\FilamentVoight\Parsers\LockfileParser;

/**
 * Fills in is_direct / is_dev from the parsed graph when the lockfile or its
 * missing manifest cannot tell.
 *
 * @phpstan-import-type ParsedPackage from LockfileParser
 */
trait DerivesFlagsFromGraph
{
    /**
     * Without a manifest, a package is direct when nothing else in the lockfile
     * requires it. This misses a package required both by the root and by another
     * package, which is acceptable until the manifest is uploaded.
     *
     * @param  array<int, ParsedPackage>  $packages
     * @return array<int, ParsedPackage>
     */
    protected function markUnrequiredPackagesDirect(array $packages): array
    {
        $required = [];

        foreach ($packages as $package) {
            foreach ($package['dependencies'] as $dependency) {
                $required[$dependency['key']] = true;
            }
        }

        foreach ($packages as $index => $package) {
            $packages[$index]['is_direct'] = ! isset($required[$package['key']]);
        }

        return $packages;
    }

    /**
     * A package is dev when it is reachable from a dev direct dependency but not
     * from any production one. Direct packages keep the flag they already have.
     *
     * @param  array<int, ParsedPackage>  $packages
     * @return array<int, ParsedPackage>
     */
    protected function propagateDevFlag(array $packages): array
    {
        $dependencyKeys = [];
        $productionRoots = [];
        $devRoots = [];

        foreach ($packages as $package) {
            $dependencyKeys[$package['key']] = array_column($package['dependencies'], 'key');

            if ($package['is_direct']) {
                $package['is_dev'] ? $devRoots[] = $package['key'] : $productionRoots[] = $package['key'];
            }
        }

        $reachableFromProduction = $this->reachableKeys($productionRoots, $dependencyKeys);
        $reachableFromDev = $this->reachableKeys($devRoots, $dependencyKeys);

        foreach ($packages as $index => $package) {
            if (! $package['is_direct']) {
                $packages[$index]['is_dev'] = isset($reachableFromDev[$package['key']])
                    && ! isset($reachableFromProduction[$package['key']]);
            }
        }

        return $packages;
    }

    /**
     * @param  array<int, string>  $roots
     * @param  array<string, array<int, string>>  $dependencyKeys
     * @return array<string, true>
     */
    private function reachableKeys(array $roots, array $dependencyKeys): array
    {
        $reached = [];
        $queue = $roots;

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($reached[$key])) {
                continue;
            }

            $reached[$key] = true;
            array_push($queue, ...($dependencyKeys[$key] ?? []));
        }

        return $reached;
    }
}
