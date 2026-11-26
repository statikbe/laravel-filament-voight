<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\McpToolError;
use Statikbe\FilamentVoight\Support\VersionConstraint;

/**
 * Where a package is installed across the visible projects, optionally narrowed to versions
 * matching a constraint such as `<4.17.21` or `>=7.0 <7.4`.
 */
class PackageUsageService
{
    /**
     * One usage per project, environment and version, however many nested copies are installed:
     * direct when any copy is, dev only when every copy is.
     *
     * @return array{usages: array<int, array<string, mixed>>, total: int, truncated: bool, next_offset: int|null}
     *
     * @throws McpToolError
     */
    public function find(McpAccess $access, string $packageName, ?string $type, ?string $versionFilter, bool $includeDev, Page $page): array
    {
        $constraint = self::versionConstraint($versionFilter);

        $usages = $this->installations($packageName, $type, $constraint)
            ->join('voight_projects as p', 'p.id', '=', 'e.project_id')
            ->groupBy('p.project_code', 'e.name', 'pk.type', 'ep.version')
            ->select(['p.project_code', 'e.name as environment', 'pk.type', 'ep.version'])
            ->selectRaw('max(case when ep.is_direct then 1 else 0 end) as is_direct, min(case when ep.is_dev then 1 else 0 end) as is_dev');

        if (! $access->seesEverything) {
            $usages->whereIn('p.id', $access->visibleProjects()->select('voight_projects.id'));
        }

        if (! $includeDev) {
            $usages->havingRaw('min(case when ep.is_dev then 1 else 0 end) = 0');
        }

        $total = DB::query()->fromSub($usages, 'usages')->count();

        $rows = $usages
            ->orderBy('p.project_code')
            ->orderBy('e.name')
            ->orderBy('ep.version')
            ->offset($page->offset)
            ->limit($page->limit)
            ->get()
            ->map(fn (object $row): array => [
                'project_code' => $row->project_code,
                'environment' => $row->environment,
                'type' => $row->type,
                'version' => $row->version,
                'is_direct' => (bool) $row->is_direct,
                'is_dev' => (bool) $row->is_dev,
            ]);

        return [
            'usages' => $rows->all(),
            ...$page->meta($total),
        ];
    }

    /**
     * The filter as a constraint, or null when it is empty.
     *
     * @throws McpToolError
     */
    public static function versionConstraint(?string $filter): ?VersionConstraint
    {
        if ($filter === null || trim($filter) === '') {
            return null;
        }

        try {
            return VersionConstraint::parse($filter);
        } catch (InvalidArgumentException) {
            throw McpToolError::invalidVersionFilter($filter);
        }
    }

    /**
     * The ids of the projects with the package installed in any environment, in a version matching the
     * constraint, as a subquery for `whereIn`. Visibility is left to the caller's project query.
     */
    public function projectIdsWithPackage(string $packageName, ?string $type, ?VersionConstraint $constraint): Builder
    {
        return $this->installations($packageName, $type, $constraint)->select('e.project_id')->distinct();
    }

    private function installations(string $packageName, ?string $type, ?VersionConstraint $constraint): Builder
    {
        $query = DB::table('voight_environment_packages as ep')
            ->join('voight_packages as pk', 'pk.id', '=', 'ep.package_id')
            ->join('voight_environments as e', 'e.id', '=', 'ep.environment_id')
            ->where('pk.name', $packageName)
            ->when($type, fn (Builder $query) => $query->where('pk.type', $type));

        return $constraint === null ? $query : $constraint->apply($query, 'ep.version_sort');
    }
}
