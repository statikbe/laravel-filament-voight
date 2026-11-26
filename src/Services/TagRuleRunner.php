<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Keeps auto-tag rules' tags live. Writes the pivot directly, so its own changes never queue another run.
 */
class TagRuleRunner
{
    public const int MAX_PASSES = 5;

    /**
     * Runs one rule (after saving or retrying it). False when its query failed.
     */
    public function run(TagRule $rule): bool
    {
        if (! $this->evaluate($rule)) {
            return false;
        }

        $this->applyType($rule->tag->type);

        return true;
    }

    /**
     * Runs every active rule, repeating while rules change tags other rules filter on.
     */
    public function runAll(): void
    {
        $changedTypes = [];

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $rules = FilamentVoight::config()->getTagRuleModel()::query()->active()->with('tag')->get();

            if ($rules->isEmpty()) {
                return;
            }

            $evaluated = $rules->filter(fn (TagRule $rule): bool => $this->evaluate($rule));

            $changedTypes = $evaluated
                ->map(fn (TagRule $rule): string => $rule->tag->type)
                ->unique()
                ->filter(fn (string $typeKey): bool => $this->applyType($typeKey))
                ->values()
                ->all();

            if ($changedTypes === []) {
                return;
            }
        }

        Log::warning('Voight tag rules kept changing tags after ' . self::MAX_PASSES . ' passes; they may depend on each other in a cycle.', [
            'tag_types' => $changedTypes,
            'rules' => FilamentVoight::config()->getTagRuleModel()::query()
                ->active()
                ->whereHas('tag', fn ($tag) => $tag->whereIn('type', $changedTypes))
                ->pluck('id')
                ->all(),
        ]);
    }

    /**
     * Deletes a rule. With $removeTags, records lose the tag unless another rule matches them or it was set by hand;
     * without, the rule's tags become manual.
     */
    public function removeRule(TagRule $rule, bool $removeTags): void
    {
        $typeKey = $rule->tag->type;

        DB::transaction(function () use ($rule, $removeTags): void {
            if (! $removeTags) {
                DB::table('voight_taggables')
                    ->where('tag_id', $rule->tag_id)
                    ->where('is_manual', false)
                    ->whereExists(fn ($match) => $match->from('voight_tag_rule_matches')
                        ->where('tag_rule_id', $rule->getKey())
                        ->whereColumn('voight_tag_rule_matches.taggable_type', 'voight_taggables.taggable_type')
                        ->whereColumn('voight_tag_rule_matches.taggable_id', 'voight_taggables.taggable_id'))
                    ->update(['is_manual' => true]);
            }

            $rule->delete();
        });

        $this->applyType($typeKey);
    }

    /**
     * Stores the rule's current matches. On a query error the rule is marked failed and its matches are kept.
     */
    private function evaluate(TagRule $rule): bool
    {
        try {
            $keys = collect(DB::select($rule->query_sql, $rule->query_bindings))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->unique()
                ->values();
        } catch (QueryException $exception) {
            $rule->update([
                'status' => TagRuleStatus::Failed,
                'last_error' => $exception->getMessage(),
                'failed_at' => now(),
            ]);

            Log::warning('Voight tag rule failed and will not run again until retried or saved again.', [
                'rule' => $rule->getKey(),
                'tag' => $rule->tag_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        DB::transaction(function () use ($rule, $keys): void {
            DB::table('voight_tag_rule_matches')->where('tag_rule_id', $rule->getKey())->delete();

            foreach ($keys->chunk(500) as $chunk) {
                DB::table('voight_tag_rule_matches')->insert($chunk->map(fn (string $key): array => [
                    'tag_rule_id' => $rule->getKey(),
                    'taggable_type' => $rule->taggable_type,
                    'taggable_id' => $key,
                ])->all());
            }
        });

        $rule->forceFill([
            'status' => TagRuleStatus::Active,
            'last_error' => null,
            'failed_at' => null,
            'last_run_at' => now(),
            'last_match_count' => $keys->count(),
        ])->saveQuietly();

        return true;
    }

    /**
     * Brings the automatic tags of one tag type in line with all its rules' stored matches. True when anything changed.
     */
    private function applyType(string $typeKey): bool
    {
        /** @var TagType|null $type */
        $type = FilamentVoight::config()->getTagTypeModel()::query()->where('key', $typeKey)->first();

        if ($type === null) {
            return false;
        }

        $tagIds = $type->tags()->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        $desired = $this->desiredPairs($tagIds, $type->is_single);
        $existing = DB::table('voight_taggables')
            ->whereIn('tag_id', $tagIds)
            ->get(['tag_id', 'taggable_type', 'taggable_id', 'is_manual']);

        $existingKeys = $existing->mapWithKeys(fn (object $row): array => [self::pairKey($row->tag_id, $row->taggable_type, $row->taggable_id) => (bool) $row->is_manual]);
        $manualRecords = $existing->filter(fn (object $row): bool => (bool) $row->is_manual)
            ->mapWithKeys(fn (object $row): array => [$row->taggable_type . '|' . $row->taggable_id => true]);

        $inserts = $desired
            ->reject(fn (object $pair): bool => $existingKeys->has(self::pairKey($pair->tag_id, $pair->taggable_type, $pair->taggable_id)))
            ->reject(fn (object $pair): bool => $type->is_single && $manualRecords->has($pair->taggable_type . '|' . $pair->taggable_id));

        $desiredKeys = $desired->mapWithKeys(fn (object $pair): array => [self::pairKey($pair->tag_id, $pair->taggable_type, $pair->taggable_id) => true]);
        $deletes = $existing
            ->reject(fn (object $row): bool => (bool) $row->is_manual)
            ->reject(fn (object $row): bool => $desiredKeys->has(self::pairKey($row->tag_id, $row->taggable_type, $row->taggable_id)));

        if ($inserts->isEmpty() && $deletes->isEmpty()) {
            return false;
        }

        DB::transaction(function () use ($inserts, $deletes): void {
            foreach ($deletes as $row) {
                DB::table('voight_taggables')
                    ->where('tag_id', $row->tag_id)
                    ->where('taggable_type', $row->taggable_type)
                    ->where('taggable_id', $row->taggable_id)
                    ->delete();
            }

            foreach ($inserts->chunk(500) as $chunk) {
                DB::table('voight_taggables')->insert($chunk->map(fn (object $pair): array => [
                    'tag_id' => $pair->tag_id,
                    'taggable_type' => $pair->taggable_type,
                    'taggable_id' => $pair->taggable_id,
                    'is_manual' => false,
                ])->values()->all());
            }
        });

        return true;
    }

    /**
     * Tag/record pairs the type's rules ask for. On a single-value type each record keeps only the pair of the most
     * recently saved rule, so competing rules do not swap the tag on every run.
     *
     * @param  list<string>  $tagIds
     * @return Collection<int, \stdClass> Rows with `tag_id`, `taggable_type` and `taggable_id`.
     */
    private function desiredPairs(array $tagIds, bool $isSingle): Collection
    {
        $pairs = DB::table('voight_tag_rule_matches as m')
            ->join('voight_tag_rules as r', 'r.id', '=', 'm.tag_rule_id')
            ->whereIn('r.tag_id', $tagIds)
            ->orderByDesc('r.updated_at')
            ->orderByDesc('r.id')
            ->get(['r.tag_id', 'm.taggable_type', 'm.taggable_id']);

        $pairs = $isSingle
            ? $pairs->unique(fn (object $pair): string => $pair->taggable_type . '|' . $pair->taggable_id)
            : $pairs->unique(fn (object $pair): string => self::pairKey($pair->tag_id, $pair->taggable_type, $pair->taggable_id));

        return $pairs->values();
    }

    private static function pairKey(string $tagId, string $taggableType, string | int $taggableId): string
    {
        return $tagId . '|' . $taggableType . '|' . $taggableId;
    }
}
