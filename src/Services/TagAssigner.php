<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Models\Vulnerability;

/**
 * The single write path for tags: checks every record first, then writes in one transaction.
 *
 * @phpstan-type Taggable Project|Environment|Customer|Package|Vulnerability
 */
class TagAssigner
{
    /**
     * Tagging needs the record's `update` ability; like Filament, a model without a policy is not restricted.
     */
    public static function userMayTag(?Authenticatable $user, Model $record): bool
    {
        if (Gate::getPolicyFor($record) === null) {
            return true;
        }

        return $user !== null && Gate::forUser($user)->allows('update', $record);
    }

    /**
     * Adds the tags; on a single-value type the record's existing tag of that type is replaced.
     *
     * @param  iterable<Taggable>  $records
     * @param  Collection<int, Tag>  $tags
     *
     * @throws TagAssignmentException
     */
    public function assign(iterable $records, Collection $tags): void
    {
        $records = $this->validated($records, $tags);

        DB::transaction(function () use ($records, $tags): void {
            foreach ($records as $record) {
                foreach ($tags->groupBy('type') as $typeTags) {
                    /** @var Tag $first */
                    $first = $typeTags->first();

                    $first->tagType->is_single
                        ? $record->syncTagIds([$first->getKey()], $first->type)
                        : $record->syncTagIds(self::keys($typeTags), $first->type, detaching: false);
                }
            }
        });
    }

    /**
     * @param  iterable<Taggable>  $records
     * @param  Collection<int, Tag>  $tags
     */
    public function remove(iterable $records, Collection $tags): void
    {
        DB::transaction(function () use ($records, $tags): void {
            foreach ($records as $record) {
                $record->tags()->detach(self::keys($tags));
            }
        });
    }

    /**
     * Makes the record's tags of one type exactly the given tags (none clears the type).
     *
     * @param  Taggable  $record
     * @param  Collection<int, Tag>  $tags
     *
     * @throws TagAssignmentException
     */
    public function sync(Model $record, TagType $type, Collection $tags): void
    {
        $foreign = $tags->reject(fn (Tag $tag): bool => $tag->type === $type->key);

        if ($foreign->isNotEmpty()) {
            throw new TagAssignmentException([voightTrans('tags.problems.wrong_type', [
                'tag' => $foreign->first()->name,
                'type' => $type->name,
            ])]);
        }

        $this->validated([$record], $tags);

        $record->syncTagIds(self::keys($tags), $type->key);
    }

    /**
     * Why the tags cannot be assigned to the record; empty when they can.
     *
     * @param  Collection<int, Tag>  $tags
     * @return list<string>
     */
    public function problems(Model $record, Collection $tags): array
    {
        $problems = [];

        foreach ($tags->groupBy('type') as $typeTags) {
            /** @var Tag $first */
            $first = $typeTags->first();
            $type = $first->tagType;

            if (! $type->appliesTo($record)) {
                $problems[] = voightTrans('tags.problems.not_applicable', [
                    'type' => $type->name,
                    'model' => TagType::modelLabel($record->getMorphClass()),
                ]);
            } elseif ($type->is_single && $typeTags->count() > 1) {
                $problems[] = voightTrans('tags.problems.single_value', ['type' => $type->name]);
            }
        }

        return $problems;
    }

    /**
     * @param  iterable<Taggable>  $records
     * @param  Collection<int, Tag>  $tags
     * @return list<Taggable>
     *
     * @throws TagAssignmentException
     */
    private function validated(iterable $records, Collection $tags): array
    {
        (new EloquentCollection($tags->all()))->loadMissing('tagType');
        $records = is_array($records) ? array_values($records) : iterator_to_array($records, false);

        $problems = collect($records)
            ->flatMap(fn (Model $record): array => $this->problems($record, $tags))
            ->unique()
            ->values()
            ->all();

        if ($problems !== []) {
            throw new TagAssignmentException($problems);
        }

        return $records;
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @return list<string>
     */
    private static function keys(Collection $tags): array
    {
        return $tags->map(fn (Tag $tag): string => (string) $tag->getKey())->values()->all();
    }
}
