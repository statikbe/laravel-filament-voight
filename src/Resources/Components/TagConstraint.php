<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;

/**
 * The one tag filter of a table: it filters on the tags of the table's own records or of a related model's, as the picked tag type decides.
 */
class TagConstraint
{
    public const string NAME = 'tag';

    /**
     * @param  array<string, array{0: class-string<Model>, 1?: string|Closure(Builder<Model>, Closure(Builder<Model>): mixed): mixed|null}>  $scopes  Short model name (`project`, `customer`, …) => [the model whose tags are filtered on, relation path from the table's records to it]. The relation is null or left out for the records themselves, and may be a closure applying the given constraint to the related records. The first scope's types are listed first.
     */
    public static function make(array $scopes): Constraint
    {
        return Constraint::make(self::NAME)
            ->label(voightTrans('models.tag.label'))
            ->icon(VoightIcon::FilterTag->resolve())
            ->operators([TagOperator::make('is')->scopes($scopes)]);
    }
}
