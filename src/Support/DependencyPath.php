<?php

namespace Statikbe\FilamentVoight\Support;

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;

/**
 * One way an installed package is pulled in, ordered from a direct dependency down
 * to the package. Each step's `constraint` and `kind` describe the edge leading
 * into that step; both are null for the first step.
 */
final class DependencyPath
{
    /**
     * @param  array<int, array{node: EnvironmentPackage, constraint: string|null, kind: DependencyKind|null}>  $steps
     */
    public function __construct(
        public array $steps,
    ) {}

    /**
     * `express@4.18.2 → send@0.18.0 (0.18.0) → ms@2.0.0 (^2.0.0, Peer)`: each step with
     * the range it was required with, plus the edge kind when it is not a regular dependency.
     */
    public function describe(): string
    {
        return collect($this->steps)
            ->map(function (array $step): string {
                $label = $step['node']->package->name . '@' . $step['node']->version;
                $edgeDetails = array_filter([
                    $step['constraint'],
                    $step['kind'] !== null && $step['kind'] !== DependencyKind::Dependency ? $step['kind']->getLabel() : null,
                ]);

                return $edgeDetails === [] ? $label : $label . ' (' . implode(', ', $edgeDetails) . ')';
            })
            ->implode(' → ');
    }
}
