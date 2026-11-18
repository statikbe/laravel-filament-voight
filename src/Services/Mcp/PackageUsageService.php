<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
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
     * @return array{usages: array<int, array<string, mixed>>, total: int, truncated: bool, next_offset: int|null}
     *
     * @throws McpToolError
     */
    public function find(McpAccess $access, string $packageName, ?string $type, ?string $versionFilter, bool $includeDev, Page $page): array
    {
        $constraint = self::versionConstraint($versionFilter);

        $query = $this->installations($packageName, $type)
            ->join('voight_projects as p', 'p.id', '=', 'e.project_id')
            ->orderBy('p.project_code')
            ->orderBy('e.name')
            ->orderBy('ep.version');

        if (! $access->seesEverything) {
            $query->whereIn('p.id', $access->visibleProjects()->select('voight_projects.id'));
        }

        $usages = $query->get(['p.project_code', 'e.name as environment', 'pk.type', 'ep.version', 'ep.is_direct', 'ep.is_dev'])
            ->filter(fn (object $row): bool => $constraint === null || $constraint->matches($row->version))
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
            'usages' => $page->slice($usages)->all(),
            ...$page->meta($usages->count()),
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
     * Ids of the projects with the package installed in any environment, in a version matching the
     * constraint. Visibility is left to the caller's project query.
     *
     * @return array<int, int>
     */
    public function projectIdsWithPackage(string $packageName, ?string $type, ?VersionConstraint $constraint): array
    {
        return $this->installations($packageName, $type)
            ->get(['e.project_id', 'ep.version'])
            ->filter(fn (object $row): bool => $constraint === null || $constraint->matches($row->version))
            ->pluck('project_id')
            ->unique()
            ->values()
            ->all();
    }

    private function installations(string $packageName, ?string $type): Builder
    {
        return DB::table('voight_environment_packages as ep')
            ->join('voight_packages as pk', 'pk.id', '=', 'ep.package_id')
            ->join('voight_environments as e', 'e.id', '=', 'ep.environment_id')
            ->where('pk.name', $packageName)
            ->when($type, fn (Builder $query) => $query->where('pk.type', $type));
    }
}
