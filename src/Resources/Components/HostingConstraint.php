<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Statikbe\FilamentVoight\Models\Hosting;

/**
 * Filters on where an environment is hosted; on projects, a project matches when any of its environments does.
 */
class HostingConstraint
{
    /**
     * @param  string  $attribute  `hosting_id` on environments, or a relation path to it such as `environments.hosting_id`.
     */
    public static function make(string $attribute): SelectConstraint
    {
        return SelectConstraint::make('hosting_id')
            ->label(voightTrans('models.environment.fields.hosting'))
            ->icon('heroicon-m-cloud')
            ->attribute($attribute)
            ->options(fn (): array => Hosting::query()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->multiple();
    }
}
