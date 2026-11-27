<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Rewrites the stored filters of tag rules to the single `tag` filter, whose `type` setting is `{scope}:{type key}`.
 * The older shapes are `tag_{type key}` and `tag_{scope}_{type key}` (type keys with `_` for `-`, values only), and
 * `tag` / `tag_{scope}` with a `type` setting holding the bare type key. Rules it cannot map stay as they are.
 *
 * Public API: published migrations call convertAll(), so its name and signature must stay stable.
 */
class TagRuleFilterConverter
{
    /**
     * @return int Rules rewritten. Rules left alone are logged as warnings.
     */
    public function convertAll(): int
    {
        $typeKeys = DB::table('voight_tag_types')->pluck('key')->all();
        $converted = 0;

        DB::table('voight_tag_rules')->select(['id', 'taggable_type', 'table_state'])->orderBy('id')->each(function (object $rule) use ($typeKeys, &$converted): void {
            $state = json_decode((string) $rule->table_state, true);
            $unmapped = [];
            $filters = self::convert($state['filters'] ?? null, self::scopeOf((string) $rule->taggable_type), $typeKeys, $unmapped);

            if ($unmapped !== []) {
                Log::warning('Voight tag rule filter not converted to the single tag filter; reopen and save the rule again.', ['rule' => $rule->id, 'constraints' => $unmapped]);
            }

            if ($filters !== ($state['filters'] ?? null)) {
                DB::table('voight_tag_rules')->where('id', $rule->id)->update(['table_state' => json_encode([...$state, 'filters' => $filters])]);
                $converted++;
            }
        });

        return $converted;
    }

    /**
     * @param  array<string, mixed>|null  $filters  The stored table filters.
     * @param  string  $ownScope  Scope of the table's own records.
     * @param  list<string>  $typeKeys  Keys of all tag types.
     * @param  list<string>  $unmapped  Receives the names of tag constraints that could not be mapped.
     * @return array<string, mixed>|null
     */
    public static function convert(?array $filters, string $ownScope, array $typeKeys, array &$unmapped = []): ?array
    {
        if (isset($filters['queryBuilder']['rules']) && is_array($filters['queryBuilder']['rules'])) {
            $filters['queryBuilder']['rules'] = self::rules($filters['queryBuilder']['rules'], $ownScope, $typeKeys, $unmapped);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $typeKeys
     * @param  list<string>  $unmapped
     * @return array<string, mixed>
     */
    private static function rules(array $rules, string $ownScope, array $typeKeys, array &$unmapped): array
    {
        foreach ($rules as $id => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            // An "or" block holds groups of rules.
            foreach ($rule['data']['groups'] ?? [] as $groupId => $group) {
                if (is_array($group) && is_array($group['rules'] ?? null)) {
                    $rules[$id]['data']['groups'][$groupId]['rules'] = self::rules($group['rules'], $ownScope, $typeKeys, $unmapped);
                }
            }

            $name = $rule['type'] ?? null;

            if (! is_string($name) || ($name !== 'tag' && ! str_starts_with($name, 'tag_'))) {
                continue;
            }

            $type = self::type($name, $rule['data']['settings']['type'] ?? null, $ownScope, $typeKeys);

            if ($type === null) {
                $unmapped[] = $name;

                continue;
            }

            if ($type !== true) {
                $rules[$id]['type'] = 'tag';
                $rules[$id]['data']['settings'] = [...($rule['data']['settings'] ?? []), 'type' => $type];
            }
        }

        return $rules;
    }

    /**
     * @param  list<string>  $typeKeys
     * @return string|true|null The new `{scope}:{type key}`, true when it already is, null when it cannot be mapped.
     */
    private static function type(string $name, mixed $storedType, string $ownScope, array $typeKeys): string | true | null
    {
        $scopes = array_map(self::scopeOf(...), array_keys(TagType::taggableModels()));

        if (is_string($storedType) && $storedType !== '') {
            if (str_contains($storedType, ':')) {
                return true;
            }

            $scope = $name === 'tag' ? $ownScope : substr($name, 4);

            return in_array($scope, $scopes, true) ? "{$scope}:{$storedType}" : null;
        }

        // The old names carry the type key, so look for the one scope and type that spell the name.
        $matches = [];

        foreach ($typeKeys as $key) {
            $spelled = str_replace('-', '_', $key);

            if ($name === "tag_{$spelled}") {
                $matches[] = "{$ownScope}:{$key}";
            }

            foreach ($scopes as $scope) {
                if ($name === "tag_{$scope}_{$spelled}") {
                    $matches[] = "{$scope}:{$key}";
                }
            }
        }

        return count($matches) === 1 && in_array(explode(':', $matches[0])[0], $scopes, true) ? $matches[0] : null;
    }

    /**
     * Scope name of a model, from its morph alias: `voight-project` is `project`.
     */
    private static function scopeOf(string $morphAlias): string
    {
        return str($morphAlias)->after('voight-')->replace('-', '_')->toString();
    }
}
