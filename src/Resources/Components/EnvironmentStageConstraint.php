<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * Stage is derived from the environment name, so it is not a column and the stock "is" operator cannot query it.
 */
class EnvironmentStageConstraint
{
    /**
     * @param  string|Closure(Builder, array<int, EnvironmentStage>): mixed|null  $relation  A relation path to the environment, null when the query is the environment itself, or a closure restricting the query to records reaching an environment of one of the stages.
     *                                                                                       "is not" negates it.
     */
    public static function make(string | Closure | null $relation = null): SelectConstraint
    {
        return SelectConstraint::make('environment_stage')
            ->label(voightTrans('models.environment.fields.stage'))
            ->icon('heroicon-m-server-stack')
            ->options(EnvironmentStage::options())
            ->multiple()
            ->operators([
                IsOperator::make()->query(
                    function (Builder $query, array $settings, bool $isInverse) use ($relation): Builder {
                        $stages = array_map(EnvironmentStage::from(...), $settings['values']);
                        $environmentModel = FilamentVoight::config()->getEnvironmentModel();
                        $constrain = fn (Builder $environment) => $environmentModel::constrainToStages($environment, ...$stages);

                        $matches = match (true) {
                            $relation instanceof Closure => fn (Builder $query) => $relation($query, $stages),
                            $relation === null => $constrain,
                            default => fn (Builder $query) => $query->whereHas($relation, $constrain),
                        };

                        return $query->{$isInverse ? 'whereNot' : 'where'}($matches);
                    },
                ),
            ]);
    }
}
