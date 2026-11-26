<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Statikbe\FilamentVoight\Enums\VoightIcon;

/**
 * Filters projects on an installed package and, optionally, the version range it is installed in.
 */
class ProjectPackageConstraint
{
    public static function make(): Constraint
    {
        return Constraint::make('installed_package')
            ->label(voightTrans('models.package.label'))
            ->icon(VoightIcon::FilterInstalledPackage->resolve())
            ->operators([ProjectPackageOperator::make('installed')]);
    }
}
