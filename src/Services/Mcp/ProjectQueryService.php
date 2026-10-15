<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\McpToolError;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Project;

/**
 * @phpstan-type ProjectFilters array{search?: string|null, team?: string|null, customer?: string|null, min_severity?: Severity|null, has_findings?: bool|null, scanned_before?: Carbon|null, sort?: string|null, limit: int}
 */
class ProjectQueryService
{
    public const array SORTS = ['findings', 'max_score', 'name', 'last_scanned'];

    /**
     * @param  ProjectFilters  $filters
     * @return array{projects: array<int, array<string, mixed>>, truncated: bool}
     */
    public function listProjects(McpAccess $access, array $filters): array
    {
        $minSeverity = $filters['min_severity'] ?? null;

        $counts = CurrentFindings::query($access, $minSeverity)
            ->groupBy('p.id')
            ->select(['p.id as project_id', ...CurrentFindings::severityColumns()]);

        $query = $access->visibleProjects()
            ->leftJoinSub($counts, 'counts', 'counts.project_id', '=', 'voight_projects.id')
            ->select(['voight_projects.*', 'counts.critical', 'counts.high', 'counts.medium', 'counts.low', 'counts.none', 'counts.total', 'counts.max_score'])
            ->with(['team', 'customer', 'environments' => fn ($environments) => $environments->orderBy('name')]);

        if (filled($filters['search'] ?? null)) {
            $search = '%' . $filters['search'] . '%';
            $query->where(fn (Builder $projects): Builder => $projects
                ->where('voight_projects.project_code', 'like', $search)
                ->orWhere('voight_projects.name', 'like', $search));
        }

        if (filled($filters['team'] ?? null)) {
            $query->whereHas('team', fn (Builder $team): Builder => $team->where('name', $filters['team']));
        }

        if (filled($filters['customer'] ?? null)) {
            $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('name', $filters['customer']));
        }

        if ($minSeverity !== null || ($filters['has_findings'] ?? null) === true) {
            $query->where('counts.total', '>', 0);
        } elseif (($filters['has_findings'] ?? null) === false) {
            $query->whereNull('counts.total');
        }

        if (($filters['scanned_before'] ?? null) !== null) {
            $scannedBefore = $filters['scanned_before'];
            $query->where(fn (Builder $projects): Builder => $projects
                ->whereHas('environments', fn (Builder $environments): Builder => $environments
                    ->where(fn (Builder $stale): Builder => $stale->whereNull('scanned_at')->orWhere('scanned_at', '<', $scannedBefore)))
                ->orWhereDoesntHave('environments'));
        }

        match ($filters['sort'] ?? 'findings') {
            'max_score' => $query->orderByRaw('coalesce(counts.max_score, -1) desc')->orderByRaw('coalesce(counts.total, 0) desc'),
            'name' => $query->orderBy('voight_projects.name'),
            'last_scanned' => $query->orderBy(
                FilamentVoight::config()->getEnvironmentModel()::query()
                    ->selectRaw('max(scanned_at)')
                    ->whereColumn('voight_environments.project_id', 'voight_projects.id'),
            ),
            default => $query->orderByRaw('coalesce(counts.total, 0) desc')->orderByRaw('coalesce(counts.max_score, -1) desc'),
        };

        $projects = $query->orderBy('voight_projects.project_code')->limit($filters['limit'] + 1)->get();

        return [
            'projects' => $projects->take($filters['limit'])->map(fn (Project $project): array => [
                ...$this->describeProject($project),
                'environments' => $project->environments->map(fn (Environment $environment): array => [
                    'name' => $environment->name,
                    'scanned_at' => $environment->scanned_at?->toIso8601String(),
                ])->all(),
                ...CurrentFindings::severitySummary((object) $project->getAttributes()),
            ])->values()->all(),
            'truncated' => $projects->count() > $filters['limit'],
        ];
    }

    /**
     * Current findings of a project, grouped by vulnerability, worst first.
     *
     * @return array{project: array<string, mixed>, vulnerabilities: array<int, array<string, mixed>>, truncated: bool}
     */
    public function projectVulnerabilities(McpAccess $access, Project $project, ?Environment $environment, ?Severity $minSeverity, bool $includeDev, int $limit): array
    {
        $findings = CurrentFindings::query($access, $minSeverity, $includeDev)
            ->where('p.id', $project->id)
            ->when($environment, fn ($query) => $query->where('e.id', $environment->id))
            ->orderByDesc('v.vulnerability_score')
            ->orderBy('v.source_id')
            ->orderBy('pk.name')
            ->orderBy('e.name')
            ->get([
                'f.vulnerability_id', 'f.package_id', 'f.installed_version', 'f.fixed_version',
                'e.id as environment_id', 'e.name as environment_name', 'pk.name as package_name', 'pk.type as package_type',
            ]);

        $copies = $this->installedCopies($findings);
        $vulnerabilityIds = $findings->pluck('vulnerability_id')->unique()->values();
        $vulnerabilities = FilamentVoight::config()->getVulnerabilityModel()::query()
            ->whereIn('id', $vulnerabilityIds->take($limit))
            ->get()
            ->keyBy('id');

        $grouped = $vulnerabilityIds->take($limit)->map(function (string $vulnerabilityId) use ($findings, $vulnerabilities, $copies): array {
            $vulnerability = $vulnerabilities[$vulnerabilityId];

            return [
                'id' => $vulnerability->source_id,
                'aliases' => $vulnerability->aliases ?? [],
                'summary' => $vulnerability->summary,
                'score' => (float) $vulnerability->vulnerability_score,
                'severity' => $vulnerability->severity->value,
                'packages' => $findings
                    ->where('vulnerability_id', $vulnerabilityId)
                    ->groupBy(fn (object $finding): string => $finding->package_id . '|' . $finding->installed_version)
                    ->map(function (Collection $packageFindings) use ($copies): array {
                        $first = $packageFindings->first();
                        $nodes = $packageFindings->flatMap(fn (object $finding): Collection => $copies->get($finding->environment_id . '|' . $finding->package_id . '|' . $finding->installed_version, collect()));

                        return [
                            'name' => $first->package_name,
                            'type' => $first->package_type,
                            'installed_version' => $first->installed_version,
                            'fixed_version' => $packageFindings->pluck('fixed_version')->filter()->first(),
                            'is_direct' => $nodes->contains('is_direct', true),
                            'is_dev' => $nodes->isNotEmpty() && $nodes->every('is_dev', true),
                            'environments' => $packageFindings->pluck('environment_name')->unique()->values()->all(),
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        });

        return [
            'project' => $this->describeProject($project),
            'vulnerabilities' => $grouped->values()->all(),
            'truncated' => $vulnerabilityIds->count() > $limit,
        ];
    }

    /**
     * The project's environment called $name. Without a name: null, or the only environment when
     * $required, so callers can omit it for single-environment projects.
     *
     * @throws McpToolError
     */
    public function environment(Project $project, ?string $name, bool $required = false): ?Environment
    {
        $environments = $project->environments()->orderBy('name')->get();
        $names = $environments->pluck('name')->implode(', ');

        if ($name !== null && $name !== '') {
            return $environments->firstWhere('name', $name)
                ?? throw new McpToolError($environments->isEmpty()
                    ? "Project `{$project->project_code}` has no environments yet."
                    : "Environment `{$name}` not found. Project `{$project->project_code}` has environments {$names}.");
        }

        if (! $required) {
            return null;
        }

        return match ($environments->count()) {
            0 => throw new McpToolError("Project `{$project->project_code}` has no environments yet; nothing has been synced."),
            1 => $environments->first(),
            default => throw new McpToolError("Project `{$project->project_code}` has environments {$names}; pass `environment`."),
        };
    }

    /**
     * @return array{code: string, name: string|null, team: string|null, customer: string|null}
     */
    public function describeProject(Project $project): array
    {
        return [
            'code' => $project->project_code,
            'name' => $project->name,
            'team' => $project->team?->name,
            'customer' => $project->customer?->name,
        ];
    }

    /**
     * The installed copies behind the findings, keyed "environment|package|version".
     *
     * @param  Collection<int, object>  $findings
     * @return Collection<string, Collection<int, EnvironmentPackage>>
     */
    private function installedCopies(Collection $findings): Collection
    {
        if ($findings->isEmpty()) {
            return collect();
        }

        return FilamentVoight::config()->getEnvironmentPackageModel()::query()
            ->whereIn('environment_id', $findings->pluck('environment_id')->unique())
            ->whereIn('package_id', $findings->pluck('package_id')->unique())
            ->get(['environment_id', 'package_id', 'version', 'is_direct', 'is_dev'])
            ->toBase()
            ->groupBy(fn (EnvironmentPackage $node): string => $node->environment_id . '|' . $node->package_id . '|' . $node->version);
    }
}
