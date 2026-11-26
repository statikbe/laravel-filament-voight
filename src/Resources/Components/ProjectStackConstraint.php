<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\UpgradeStatus;
use Statikbe\FilamentVoight\Services\ProjectStackService;

/**
 * Stack constraints on projects. They read the representative environment, so no column backs them
 * and the stock "is" operator cannot query them; "is not" negates the match.
 */
class ProjectStackConstraint
{
    public static function phpVersion(): SelectConstraint
    {
        return self::make(
            'php_version',
            voightTrans('models.project.fields.php_version'),
            ProjectStackService::phpVersionOptions(...),
            // Only offered versions are queried: the values are interpolated into a LIKE pattern.
            fn (Builder $query, array $values): Builder => ProjectStackService::constrainToPhpVersions(
                $query,
                array_values(array_intersect($values, array_keys(ProjectStackService::phpVersionOptions()))),
            ),
        );
    }

    public static function upgradeStatus(): SelectConstraint
    {
        return self::make(
            'upgrade_status',
            voightTrans('models.project.fields.upgrade_status'),
            UpgradeStatus::options(),
            fn (Builder $query, array $values): Builder => ProjectStackService::constrainToUpgradeStatuses(
                $query,
                ...array_map(UpgradeStatus::from(...), $values),
            ),
        );
    }

    /**
     * @param  array<int|string, string|int>|Closure(): array<int|string, string|int>  $options
     * @param  Closure(Builder, array<int, string>): Builder  $constrain
     */
    private static function make(string $name, string $label, array | Closure $options, Closure $constrain): SelectConstraint
    {
        return SelectConstraint::make($name)
            ->label($label)
            ->options($options)
            ->multiple()
            ->operators([
                IsOperator::make()->query(
                    fn (Builder $query, array $settings, bool $isInverse): Builder => $query->{$isInverse ? 'whereNot' : 'where'}(
                        fn (Builder $query): Builder => $constrain($query, $settings['values']),
                    ),
                ),
            ]);
    }
}
