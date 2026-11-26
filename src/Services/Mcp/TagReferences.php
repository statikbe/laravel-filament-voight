<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpToolError;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Tags as MCP clients refer to them: `{type: <tag type key>, tag: <tag name or slug>}`.
 */
class TagReferences
{
    public const string DESCRIPTION = 'Tag references `{type, tag}`: `type` is a tag type key from `list_tags`, `tag` a tag name or slug (case-insensitive).';

    /**
     * Input schema for a list of tag references.
     */
    public static function schema(JsonSchema $schema): Type
    {
        return $schema->array()->items($schema->object([
            'type' => $schema->string()->required(),
            'tag' => $schema->string()->required(),
        ]));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(string $attribute): array
    {
        return [
            $attribute => ['nullable', 'array'],
            "{$attribute}.*.type" => ['required', 'string'],
            "{$attribute}.*.tag" => ['required', 'string'],
        ];
    }

    /**
     * Resolves references to tags, collecting a problem for each unknown type or tag.
     *
     * @param  array<int, array{type: string, tag: string}>  $references
     * @return array{tags: Collection<int, Tag>, problems: list<string>}
     */
    public static function resolve(array $references): array
    {
        $typeKeys = array_values(array_unique(array_map(fn (array $reference): string => trim($reference['type']), $references)));

        /** @var \Illuminate\Support\Collection<string, TagType> $types */
        $types = FilamentVoight::config()->getTagTypeModel()::query()
            ->whereIn('key', $typeKeys)
            ->with('tags')
            ->get()
            ->keyBy('key');

        $tags = new Collection;
        $problems = [];

        foreach ($references as $reference) {
            $typeKey = trim($reference['type']);
            $name = mb_strtolower(trim($reference['tag']));
            $type = $types->get($typeKey);

            if ($type === null) {
                $problems[] = "Unknown tag type \"{$typeKey}\".";

                continue;
            }

            $tag = $type->tags->first(fn (Tag $tag): bool => mb_strtolower(trim($tag->name)) === $name || $tag->slug === Str::slug($name));

            if ($tag === null) {
                $problems[] = 'Unknown tag "' . trim($reference['tag']) . "\" for tag type \"{$typeKey}\".";

                continue;
            }

            $tags->push($tag);
        }

        return ['tags' => $tags->unique('id')->values(), 'problems' => array_values(array_unique($problems))];
    }

    /**
     * Resolves references or fails with every problem.
     *
     * @param  array<int, array{type: string, tag: string}>  $references
     * @return Collection<int, Tag>
     *
     * @throws McpToolError
     */
    public static function resolveOrFail(array $references): Collection
    {
        ['tags' => $tags, 'problems' => $problems] = self::resolve($references);

        if ($problems !== []) {
            throw new McpToolError(implode("\n", $problems) . "\nAsk an admin to create missing tag types or tags.");
        }

        return $tags;
    }

    /**
     * Keeps records that, for every tag type referenced, have any of its referenced tags.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Collection<int, Tag>  $tags
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, Collection $tags): Builder
    {
        foreach ($tags->groupBy('type') as $typeTags) {
            $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->whereKey($typeTags->pluck('id')->all()));
        }

        return $query;
    }

    /**
     * A record's tags as references.
     *
     * @return list<array{type: string, tag: string}>
     */
    public static function of(Model $record): array
    {
        /** @var iterable<Tag> $tags */
        $tags = $record->getAttribute('tags') ?? [];

        return collect($tags)
            ->map(fn (Tag $tag): array => ['type' => $tag->type, 'tag' => $tag->name])
            ->values()
            ->all();
    }
}
