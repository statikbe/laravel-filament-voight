<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;

/**
 * Filters installations on the version range they are installed in.
 */
class InstalledVersionConstraint
{
    public static function make(): Constraint
    {
        return Constraint::make('installed_version')
            ->label(voightTrans('models.package.fields.installed_version'))
            ->icon('heroicon-m-tag')
            ->operators([InstalledVersionRangeOperator::make('inRange')]);
    }
}
