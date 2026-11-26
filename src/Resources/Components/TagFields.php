<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Services\TagAssigner;

/**
 * One select per tag type that applies to a model, offering only existing tags.
 */
class TagFields
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  bool  $saveWithRecord  True in resource forms (saved with the record); false in action modals, where the caller saves through save().
     * @return list<Select>
     */
    public static function make(string $modelClass, bool $saveWithRecord = true): array
    {
        return self::types($modelClass)
            ->map(fn (TagType $type): Select => self::field($type, $saveWithRecord))
            ->values()
            ->all();
    }

    public static function fieldName(TagType $type): string
    {
        return 'voight_tag_' . str_replace('-', '_', $type->key);
    }

    /**
     * The form state of a record's tags, keyed by field name.
     *
     * @return array<string, string|list<string>|null>
     */
    public static function state(Model $record): array
    {
        return self::types($record::class)
            ->mapWithKeys(fn (TagType $type): array => [self::fieldName($type) => self::fieldState($record, $type)])
            ->all();
    }

    /**
     * Syncs every applicable type from form data keyed by field name.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(Model $record, array $data): void
    {
        foreach (self::types($record::class) as $type) {
            $fieldName = self::fieldName($type);

            if (array_key_exists($fieldName, $data)) {
                self::sync($record, $type, $data[$fieldName]);
            }
        }
    }

    private static function field(TagType $type, bool $saveWithRecord): Select
    {
        $field = Select::make(self::fieldName($type))
            ->label($type->name)
            ->helperText($type->description)
            ->options(fn (): array => TagOptions::names($type->tags()->get()))
            ->multiple(! $type->is_single)
            ->searchable();

        if (! $saveWithRecord) {
            return $field;
        }

        return $field
            ->afterStateHydrated(function (Select $component, ?Model $record) use ($type): void {
                if ($record?->exists) {
                    $component->state(self::fieldState($record, $type));
                }
            })
            ->dehydrated(false)
            ->saveRelationshipsUsing(fn (Model $record, mixed $state) => self::sync($record, $type, $state));
    }

    /**
     * @return string|list<string>|null
     */
    private static function fieldState(Model $record, TagType $type): string | array | null
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $record->tags()->where('type', $type->key)->get(); // @phpstan-ignore method.notFound

        $ids = $tags->map(fn (Tag $tag): string => (string) $tag->getKey())->values()->all();

        return $type->is_single ? ($ids[0] ?? null) : $ids;
    }

    private static function sync(Model $record, TagType $type, mixed $state): void
    {
        $ids = array_values(array_filter(Arr::wrap($state)));
        $tags = FilamentVoight::config()->getTagModel()::query()->whereKey($ids)->get();

        app(TagAssigner::class)->sync($record, $type, $tags);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return Collection<int, TagType>
     */
    private static function types(string $modelClass): Collection
    {
        return FilamentVoight::config()->getTagTypeModel()::query()->forModel($modelClass)->ordered()->get();
    }
}
