<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Tags\Tag;

/**
 * A spatie/laravel-tags tag of type `hosting`: where an environment runs, e.g. Combell, Level27 or the
 * customer's own server. An environment can have several.
 *
 * @property int $id
 * @property string $name
 * @property string|null $type
 */
class HostingTag extends Tag
{
    public const string TYPE = 'hosting';

    protected $table = 'tags';

    protected static function booted(): void
    {
        static::addGlobalScope('hosting', fn (Builder $query) => $query->where($query->qualifyColumn('type'), self::TYPE));

        static::creating(function (HostingTag $tag): void {
            $tag->type = self::TYPE;
        });
    }

    /**
     * @return MorphToMany<Environment, $this>
     */
    public function environments(): MorphToMany
    {
        return $this->morphedByMany(Environment::class, 'taggable', 'taggables', 'tag_id', 'taggable_id');
    }

    /**
     * Names compare case-insensitively and ignore surrounding whitespace, in the current locale.
     *
     * @param  Builder<HostingTag>  $query
     */
    public function scopeNamed(Builder $query, string $name): void
    {
        $column = $query->getQuery()->getGrammar()->wrap('name->' . static::getLocale());

        $query->whereRaw("LOWER({$column}) = ?", [self::normalize($name)]);
    }

    /**
     * The hosting tags with these names, creating the ones that do not exist yet. Blank and duplicate
     * names (case-insensitive) are dropped; a new tag keeps the name as given, trimmed.
     *
     * @param  array<int, string>  $names
     * @return array{tags: Collection<int, HostingTag>, created: array<int, string>}
     */
    public static function findOrCreateMany(array $names): array
    {
        $names = collect($names)
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => self::normalize($name))
            ->values();

        $existing = self::query()->get()->keyBy(fn (HostingTag $tag): string => self::normalize($tag->name));
        $created = [];

        $tags = $names->map(function (string $name) use ($existing, &$created): HostingTag {
            if ($existing->has(self::normalize($name))) {
                return $existing->get(self::normalize($name));
            }

            $created[] = $name;

            return self::query()->create(['name' => [static::getLocale() => $name]]);
        });

        return ['tags' => new Collection($tags->all()), 'created' => $created];
    }

    private static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
