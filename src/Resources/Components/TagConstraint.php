<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Filters on the tags of one type, on the table's own records or on a related model's.
 */
class TagConstraint
{
    /**
     * One constraint per tag type that applies to the model.
     *
     * @param  class-string<Model>  $modelClass  The model whose tags are filtered on.
     * @param  string|Closure(Builder<Model>, Closure(Builder<Model>): mixed): mixed|null  $relation  Relation path from the table's records to that model, null for the records themselves, or a closure applying the given constraint to the related records.
     * @param  string|null  $scope  Short name of the related model (`project`, `customer`, …), used in the name and label.
     * @return list<SelectConstraint>
     */
    public static function forModel(string $modelClass, string | Closure | null $relation = null, ?string $scope = null): array
    {
        return FilamentVoight::config()->getTagTypeModel()::query()
            ->forModel($modelClass)
            ->ordered()
            ->get()
            ->map(fn (TagType $type): SelectConstraint => self::make($type, $relation, $scope))
            ->values()
            ->all();
    }

    /**
     * @param  string|Closure(Builder<Model>, Closure(Builder<Model>): mixed): mixed|null  $relation
     */
    public static function make(TagType $type, string | Closure | null $relation = null, ?string $scope = null): SelectConstraint
    {
        return SelectConstraint::make(self::name($type, $scope))
            ->label($scope === null ? $type->name : voightTrans("models.{$scope}.label") . ' › ' . $type->name)
            ->icon(VoightIcon::FilterTag->resolve())
            ->options(fn (): array => $type->tags()->get()->mapWithKeys(fn (Tag $tag): array => [$tag->getKey() => $tag->name])->all())
            ->multiple()
            ->searchable()
            ->operators([
                IsOperator::make()->query(
                    function (Builder $query, array $settings, bool $isInverse) use ($relation): Builder {
                        $tagIds = $settings['values'] ?? [];
                        $constrain = fn (Builder $tagged) => $tagged->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tagIds));

                        $matches = match (true) {
                            $relation instanceof Closure => fn (Builder $query) => $relation($query, $constrain),
                            $relation === null => $constrain,
                            default => fn (Builder $query) => $query->whereHas($relation, $constrain),
                        };

                        return $query->{$isInverse ? 'whereNot' : 'where'}($matches);
                    },
                ),
            ]);
    }

    public static function name(TagType $type, ?string $scope = null): string
    {
        return 'tag_' . ($scope === null ? '' : $scope . '_') . str_replace('-', '_', $type->key);
    }
}
