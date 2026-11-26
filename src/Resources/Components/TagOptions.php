<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Tag select options and labels shared by the bulk actions, columns and entries.
 */
class TagOptions
{
    /**
     * Applicable tags grouped by type name.
     *
     * @param  class-string<Model>|Model  $model
     * @return array<string, array<string, string>>
     */
    public static function grouped(string | Model $model): array
    {
        return FilamentVoight::config()->getTagTypeModel()::query()
            ->forModel($model)
            ->ordered()
            ->with('tags')
            ->get()
            ->mapWithKeys(fn (TagType $type): array => [
                $type->name => $type->tags->mapWithKeys(fn (Tag $tag): array => [$tag->getKey() => $tag->name])->all(),
            ])
            ->filter()
            ->all();
    }

    /**
     * "Type: Tag" labels for a record's tags.
     *
     * @return list<string>
     */
    public static function labels(Model $record): array
    {
        /** @var iterable<Tag> $tags */
        $tags = $record->getAttribute('tags') ?? [];

        return collect($tags)
            ->map(fn (Tag $tag): string => self::typeName($tag->type) . ': ' . $tag->name)
            ->values()
            ->all();
    }

    private static function typeName(string $key): string
    {
        // Once per request (flushed between Octane requests and tests), not per worker.
        $names = once(fn (): array => FilamentVoight::config()->getTagTypeModel()::query()->get()
            ->mapWithKeys(fn (TagType $type): array => [$type->key => $type->name])
            ->all());

        return $names[$key] ?? $key;
    }
}
