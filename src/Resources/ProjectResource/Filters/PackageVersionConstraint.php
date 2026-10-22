<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Filters;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Statikbe\FilamentVoight\Resources\ProjectResource\Filters\PackageVersionConstraint\Operators\SatisfiesRangeOperator;

/**
 * Query builder constraint for projects: "package X is installed at a version
 * inside range Y" in any of the project's environments.
 */
class PackageVersionConstraint extends Constraint
{
    public static function getDefaultName(): ?string
    {
        return 'package_version';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(voightTrans('filters.package_version.label'));

        $this->icon(Heroicon::OutlinedShieldExclamation);

        $this->operators([
            SatisfiesRangeOperator::class,
        ]);
    }
}
