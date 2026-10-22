<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\ProjectResource;

/**
 * Project, customer and team filters offering only what the host's project scoping lets the user see.
 */
class ProjectConstraint
{
    public static function project(string $attribute): SelectConstraint
    {
        return SelectConstraint::make('project')
            ->label(voightTrans('models.audit_run.fields.project'))
            ->attribute($attribute)
            ->options(fn (): array => ProjectResource::getEloquentQuery()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->multiple();
    }

    public static function customer(string $attribute): SelectConstraint
    {
        return SelectConstraint::make('customer')
            ->label(voightTrans('models.project.fields.customer'))
            ->attribute($attribute)
            ->options(fn (): array => Customer::query()->whereIn('id', ProjectResource::getEloquentQuery()->select('customer_id'))->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->multiple();
    }

    public static function team(string $attribute): SelectConstraint
    {
        return SelectConstraint::make('team')
            ->label(voightTrans('models.project.fields.team'))
            ->attribute($attribute)
            ->options(fn (): array => Team::query()->whereIn('id', ProjectResource::getEloquentQuery()->select('team_id'))->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->multiple();
    }
}
