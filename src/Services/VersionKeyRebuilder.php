<?php

namespace Statikbe\FilamentVoight\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Support\VersionKey;

/**
 * Recomputes the stored version keys from their version columns. Walks each table by id in chunks and
 * updates only the rows whose key changed, grouped per new key and matched by id: the version columns
 * are not indexed, and a ci collation would treat versions that differ in case as equal. Plain query
 * updates: no model events run.
 *
 * Public API: published migrations call rebuild(), so its name and signature must stay stable.
 */
class VersionKeyRebuilder
{
    private const int CHUNK = 1000;

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
            $updated[$name] = 0;

            DB::table($name)
                ->select(['id', $source, $key])
                ->chunkById(self::CHUNK, function (Collection $rows) use ($name, $source, $key, $encode, &$updated): void {
                    $updated[$name] += $this->updateChunk($name, $key, $this->staleIdsByKey($rows, $source, $key, $encode));
                });
        }

        return $updated;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  Closure(string): ?string  $encode
     * @return array<string, array<int, string>> new key ('' for none) => ids whose stored key differs
     */
    private function staleIdsByKey(Collection $rows, string $source, string $key, Closure $encode): array
    {
        $stale = [];

        foreach ($rows as $row) {
            $version = $row->{$source};
            $value = $version === null ? null : $encode((string) $version);

            if ($value !== $row->{$key}) {
                $stale[$value ?? ''][] = $row->id;
            }
        }

        return $stale;
    }

    /**
     * @param  array<string, array<int, string>>  $staleIdsByKey
     */
    private function updateChunk(string $table, string $key, array $staleIdsByKey): int
    {
        $updated = 0;

        foreach ($staleIdsByKey as $value => $ids) {
            $updated += DB::table($table)->whereIn('id', $ids)->update([$key => $value === '' ? null : $value]);
        }

        return $updated;
    }
}
