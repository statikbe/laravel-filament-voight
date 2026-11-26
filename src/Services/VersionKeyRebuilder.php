<?php

namespace Statikbe\FilamentVoight\Services;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Support\VersionKey;

/**
 * Recomputes the stored version keys from their version columns. Works per distinct version, so the
 * number of updates follows the number of different versions, not the number of rows. Plain query
 * updates: no model events run. Used by the migrations that add the keys and by voight:rebuild-version-keys.
 */
class VersionKeyRebuilder
{
    /**
     * @return array<string, array{source: string, key: string, encode: Closure(string): ?string}>
     */
    private static function tables(): array
    {
        return [
            'voight_environment_packages' => ['source' => 'version', 'key' => 'version_sort', 'encode' => VersionKey::for(...)],
            'voight_audit_findings' => ['source' => 'installed_version', 'key' => 'installed_version_sort', 'encode' => VersionKey::for(...)],
            'voight_environment_system_details' => ['source' => 'php_version', 'key' => 'php_version_sort', 'encode' => VersionKey::forPhp(...)],
        ];
    }

    /**
     * @param  string|null  $table  one of the keyed tables, or null for all of them
     * @return array<string, int> table => rows updated
     */
    public function rebuild(?string $table = null): array
    {
        $tables = $table === null ? self::tables() : array_intersect_key(self::tables(), [$table => true]);
        $updated = [];

        foreach ($tables as $name => ['source' => $source, 'key' => $key, 'encode' => $encode]) {
            $updated[$name] = DB::table($name)
                ->whereNull($source)
                ->whereNotNull($key)
                ->update([$key => null]);

            foreach (DB::table($name)->whereNotNull($source)->distinct()->pluck($source) as $version) {
                $updated[$name] += $this->update(DB::table($name)->where($source, $version), $key, $encode((string) $version));
            }
        }

        return $updated;
    }

    private function update(Builder $rows, string $key, ?string $value): int
    {
        $stale = $value === null
            ? $rows->whereNotNull($key)
            : $rows->where(fn (Builder $query) => $query->whereNull($key)->orWhere($key, '!=', $value));

        return $stale->update([$key => $value]);
    }
}
