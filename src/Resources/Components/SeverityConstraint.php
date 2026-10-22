<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\Severity;

/**
 * Severity is derived from the vulnerability score, so the stock "is" operator is queried via score ranges.
 */
class SeverityConstraint
{
    /**
     * @param  string|Closure(Builder, Severity): mixed|null  $relation  A relation path to the vulnerability, null when the query is the vulnerability itself, or a closure restricting the query to records of one severity.
     *                                                                   The selected severities are ORed and "is not" negates them.
     */
    public static function make(string $name, string | Closure | null $relation = null): SelectConstraint
    {
        return SelectConstraint::make($name)
            ->options(Severity::options())
            ->multiple()
            ->operators([
                IsOperator::make()->query(
                    function (Builder $query, array $settings, bool $isInverse) use ($relation): Builder {
                        $severities = array_map(Severity::from(...), $settings['values']);

                        $matchesScore = fn (Builder $query): Builder => $query->where(function (Builder $query) use ($severities): void {
                            foreach ($severities as $severity) {
                                $query->orWhereBetween('vulnerability_score', $severity->scoreRange());
                            }
                        });

                        $matches = match (true) {
                            $relation instanceof Closure => function (Builder $query) use ($severities, $relation): void {
                                foreach ($severities as $severity) {
                                    $query->orWhere(fn (Builder $query) => $relation($query, $severity));
                                }
                            },
                            $relation === null => $matchesScore,
                            default => fn (Builder $query): Builder => $query->whereHas($relation, $matchesScore),
                        };

                        return $query->{$isInverse ? 'whereNot' : 'where'}($matches);
                    },
                ),
            ]);
    }
}
