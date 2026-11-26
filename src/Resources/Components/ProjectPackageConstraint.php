<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;

/**
 * Filters projects on an installed package and, optionally, the version range it is installed in.
 */
class ProjectPackageConstraint
{
    public static function make(): Constraint
    {
        return Constraint::make('installed_package')
            ->label(voightTrans('models.package.label'))
            ->icon('heroicon-m-cube')
            ->operators([ProjectPackageOperator::make('installed')]);
    }
}
