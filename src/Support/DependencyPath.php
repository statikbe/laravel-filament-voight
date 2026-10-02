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
}
