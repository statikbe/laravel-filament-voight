<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Models\HostingTag;

/**
 * Filters on the `hosting` tags of environments: a record matches when one of its environments has any of the
 * chosen hostings. Hostings are tags, not a column, so the stock "is" operator cannot query them.
 */
class HostingConstraint
{
    /**
     * @param  string|null  $environmentRelation  A relation path to the environments, null when the query is the environment itself.
     */
    public static function make(?string $environmentRelation = null): SelectConstraint
    {
        $relation = $environmentRelation === null ? 'hostings' : "{$environmentRelation}.hostings";

        return SelectConstraint::make('hosting')
            ->label(voightTrans('models.environment.fields.hosting'))
            ->icon('heroicon-m-cloud')
            ->options(fn (): array => HostingTag::query()->get()->sortBy('name')->mapWithKeys(fn (HostingTag $tag): array => [$tag->id => $tag->name])->all())
            ->searchable()
            ->multiple()
            ->operators([
                IsOperator::make()->query(
                    fn (Builder $query, array $settings, bool $isInverse): Builder => $query->{$isInverse ? 'whereDoesntHave' : 'whereHas'}(
                        $relation,
                        fn (Builder $hostings): Builder => $hostings->whereKey($settings['values']),
                    ),
                ),
            ]);
    }
}
