<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\McpToolError;

/**
 * Where a package is installed across the visible projects, optionally narrowed to versions
 * matching one comparison such as `<4.17.21`.
 */
class PackageUsageService
{
    /**
     * @return array{usages: array<int, array<string, mixed>>, truncated: bool}
     *
     * @throws McpToolError
     */
    public function find(McpAccess $access, string $packageName, ?string $type, ?string $versionFilter, bool $includeDev, int $limit): array
    {
        $comparison = $versionFilter === null || trim($versionFilter) === '' ? null : self::parseComparison($versionFilter);

        $query = DB::table('voight_environment_packages as ep')
            ->join('voight_packages as pk', 'pk.id', '=', 'ep.package_id')
            ->join('voight_environments as e', 'e.id', '=', 'ep.environment_id')
            ->join('voight_projects as p', 'p.id', '=', 'e.project_id')
            ->where('pk.name', $packageName)
            ->when($type, fn ($query) => $query->where('pk.type', $type))
            ->orderBy('p.project_code')
            ->orderBy('e.name')
            ->orderBy('ep.version');

        if (! $access->seesEverything) {
            $query->whereIn('p.id', $access->visibleProjects()->select('voight_projects.id'));
        }

        $usages = $query->get(['p.project_code', 'e.name as environment', 'pk.type', 'ep.version', 'ep.is_direct', 'ep.is_dev'])
            ->filter(fn (object $row): bool => $comparison === null || version_compare(self::normalize($row->version), $comparison['version'], $comparison['operator']))
            // One entry per project, environment and version, however many nested copies are installed.
            ->groupBy(fn (object $row): string => $row->project_code . '|' . $row->environment . '|' . $row->type . '|' . $row->version)
            ->map(fn (Collection $copies): array => [
                'project_code' => $copies->first()->project_code,
                'environment' => $copies->first()->environment,
                'type' => $copies->first()->type,
                'version' => $copies->first()->version,
                'is_direct' => $copies->contains(fn (object $copy): bool => (bool) $copy->is_direct),
                'is_dev' => $copies->every(fn (object $copy): bool => (bool) $copy->is_dev),
            ])
            ->filter(fn (array $usage): bool => $includeDev || ! $usage['is_dev'])
            ->values();

        return [
            'usages' => $usages->take($limit)->all(),
            'truncated' => $usages->count() > $limit,
        ];
    }

    /**
     * @return array{operator: string, version: string}
     *
     * @throws McpToolError
     */
    public static function parseComparison(string $filter): array
    {
        if (preg_match('/^\s*(<=|>=|<|>|=)?\s*(v?\d[^\s]*)\s*$/i', $filter, $matches) !== 1) {
            throw new McpToolError("Invalid version filter `{$filter}`. Use one comparison: <, <=, >, >= or = followed by a version, e.g. `<4.17.21`.");
        }

        return [
            'operator' => $matches[1] === '' ? '=' : ($matches[1] === '=' ? '==' : $matches[1]),
            'version' => self::normalize($matches[2]),
        ];
    }

    private static function normalize(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
