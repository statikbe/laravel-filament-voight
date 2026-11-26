<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Statikbe\FilamentVoight\Enums\VoightIcon;

/**
 * Filters installations on the version range they are installed in.
 */
class InstalledVersionConstraint
{
    public static function make(): Constraint
    {
        return Constraint::make('installed_version')
            ->label(voightTrans('models.package.fields.installed_version'))
            ->icon(VoightIcon::FilterInstalledVersion->resolve())
            ->operators([InstalledVersionRangeOperator::make('inRange')]);
    }
}
