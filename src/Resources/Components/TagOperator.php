<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Matches records carrying any of the selected tags. The tag type is stored as `{scope}:{type key}`: its scope decides
 * whether the tags are those of the table's own records or of a related model.
 */
class TagOperator extends Operator
{
    /** @var array<string, array{0: class-string<Model>, 1?: string|Closure(Builder<Model>, Closure(Builder<Model>): mixed): mixed|null}> */
    protected array $scopes = [];

    /**
     * @param  array<string, array{0: class-string<Model>, 1?: string|Closure(Builder<Model>, Closure(Builder<Model>): mixed): mixed|null}>  $scopes
     */
    public function scopes(array $scopes): static
    {
        $this->scopes = $scopes;

        return $this;
    }

    #[\Override]
    public function getLabel(): string
    {
        return __($this->isInverse()
            ? 'filament-query-builder::query-builder.operators.select.is.label.inverse'
            : 'filament-query-builder::query-builder.operators.select.is.label.direct');
    }

    #[\Override]
    public function getSummary(): string
    {
        [$scope, $key] = $this->parseType(Arr::get($this->getSettings(), 'type'));
        $typeName = $this->tagTypes($scope)->get($key)?->name ?? '';

        if ($typeName !== '' && count($this->scopes) > 1) {
            $typeName = $this->scopeLabel($scope) . ' › ' . $typeName;
        }

        $tags = $this->selectedTags()->map(fn (Tag $tag): string => $tag->name)->implode(', ');

        return $this->getLabel() . ' ' . $typeName . ': ' . $tags;
    }

    /**
     * @return array<int, Select>
     */
    #[\Override]
    public function getFormSchema(): array
    {
        return [
            Select::make('type')
                ->label(voightTrans('models.tag_type.label'))
                ->options(fn (): array => $this->typeOptions())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('values', []))
                // Filament puts operator and settings in one 3-column grid at @xl: type and tags then share the row. Without a type, type takes the full row so no empty cell remains.
                ->columnSpan(['default' => 'full', '@xl' => fn (Get $get): int | string => filled($get('type')) ? 1 : 'full']),
            Select::make('values')
                ->label(voightTrans('models.tag.plural'))
                ->options(fn (Get $get): array => $this->tagOptions($get('type')))
                ->multiple()
                ->searchable()
                ->required()
                ->visible(fn (Get $get): bool => filled($get('type')))
                ->columnSpan(['default' => 'full', '@xl' => 1]),
        ];
    }

    #[\Override]
    public function apply(Builder $query, string $qualifiedColumn): Builder
    {
        [$scope] = $this->parseType(Arr::get($this->getSettings(), 'type'));

        if (! isset($this->scopes[$scope])) {
            return $query->whereRaw('1 = 0');
        }

        $tagIds = $this->tagIds();
        $constrain = fn (Builder $tagged) => $tagged->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tagIds));
        $relation = $this->scopes[$scope][1] ?? null;

        $matches = match (true) {
            $relation instanceof Closure => fn (Builder $query) => $relation($query, $constrain),
            $relation === null => $constrain,
            default => fn (Builder $query) => $query->whereHas($relation, $constrain),
        };

        return $query->{$this->isInverse() ? 'whereNot' : 'where'}($matches);
    }

    /**
     * Type options, grouped by scope when the table has several.
     *
     * @return array<string, string|array<string, string>>
     */
    private function typeOptions(): array
    {
        $options = collect(array_keys($this->scopes))->mapWithKeys(fn (string $scope): array => [
            $this->scopeLabel($scope) => $this->tagTypes($scope)->mapWithKeys(fn (TagType $type): array => ["{$scope}:{$type->key}" => $type->name])->all(),
        ])->filter();

        return count($this->scopes) > 1 ? $options->all() : $options->first() ?? [];
    }

    /**
     * @return array<int|string, string>
     */
    private function tagOptions(mixed $type): array
    {
        [$scope, $key] = $this->parseType($type);

        return TagOptions::names($this->tagTypes($scope)->get($key)?->tags ?? []);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseType(mixed $type): array
    {
        return array_pad(explode(':', is_string($type) ? $type : '', 2), 2, '');
    }

    private function scopeLabel(string $scope): string
    {
        return voightTrans("models.{$scope}.label");
    }

    /**
     * Tag types applying to the scope's model, keyed by type key.
     *
     * @return Collection<string, TagType>
     */
    private function tagTypes(string $scope): Collection
    {
        $model = $this->scopes[$scope][0] ?? null;

        return $model === null
            ? new Collection
            : $this->allTagTypes()->filter(fn (TagType $type): bool => $type->appliesTo($model))->keyBy('key');
    }

    /**
     * @return Collection<int, TagType>
     */
    private function allTagTypes(): Collection
    {
        return once(fn () => FilamentVoight::config()->getTagTypeModel()::query()->ordered()->with('tags')->get());
    }

    /**
     * Settings come from the request, so keep scalars only.
     *
     * @return array<int, int|string>
     */
    private function tagIds(): array
    {
        return array_values(array_filter(Arr::wrap($this->getSettings()['values'] ?? []), is_scalar(...)));
    }

    /**
     * @return Collection<int, Tag>
     */
    private function selectedTags(): Collection
    {
        return FilamentVoight::config()->getTagModel()::query()->findMany($this->tagIds());
    }
}
