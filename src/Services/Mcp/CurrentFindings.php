<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;

/**
 * "Current findings": the findings of each environment's latest audit run, exactly what the
 * widgets show, limited to the projects the caller may see. Aliases: f (finding), r (run),
 * e (environment), p (project), v (vulnerability), pk (package).
 */
final class CurrentFindings
{
    /**
     * @param  bool  $includeDev  false drops findings whose installed copies are all dev dependencies
     */
    public static function query(McpAccess $access, ?Severity $minSeverity = null, bool $includeDev = true): Builder
    {
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        $query = DB::table('voight_audit_findings as f')
            ->join('voight_audit_runs as r', 'r.id', '=', 'f.audit_run_id')
            ->join('voight_environments as e', 'e.id', '=', 'r.environment_id')
            ->join('voight_projects as p', 'p.id', '=', 'e.project_id')
            ->join('voight_vulnerabilities as v', 'v.id', '=', 'f.vulnerability_id')
            ->join('voight_packages as pk', 'pk.id', '=', 'f.package_id')
            ->whereIn('f.audit_run_id', $auditRunModel::latestIdsPerEnvironment());

        if (! $access->seesEverything) {
            $query->whereIn('p.id', $access->visibleProjects()->select('voight_projects.id'));
        }

        if ($minSeverity !== null) {
            $query->where('v.vulnerability_score', '>=', $minSeverity->minimumScore());
        }

        if (! $includeDev) {
            $query->where(fn (Builder $nodes): Builder => $nodes
                ->whereExists(self::installedCopies()->where('ep.is_dev', false))
                ->orWhereNotExists(self::installedCopies()));
        }

        return $query;
    }

    /**
     * Select expressions counting the grouped findings per severity, plus `total` and `max_score`.
     *
     * @return array<int, Expression>
     */
    public static function severityColumns(): array
    {
        $columns = [];
        $upperBound = null;

        foreach ([Severity::Critical, Severity::High, Severity::Medium, Severity::Low] as $severity) {
            $condition = 'v.vulnerability_score >= ' . $severity->minimumScore()
                . ($upperBound === null ? '' : ' and v.vulnerability_score < ' . $upperBound);
            $columns[] = DB::raw("sum(case when {$condition} then 1 else 0 end) as {$severity->value}");
            $upperBound = $severity->minimumScore();
        }

        $columns[] = DB::raw('sum(case when v.vulnerability_score < ' . $upperBound . ' then 1 else 0 end) as ' . Severity::None->value);
        $columns[] = DB::raw('count(*) as total');
        $columns[] = DB::raw('max(v.vulnerability_score) as max_score');

        return $columns;
    }

    /**
     * Shapes a row selected with severityColumns(); null (no findings) counts as zero.
     *
     * @return array{findings: array{critical: int, high: int, medium: int, low: int, none: int, total: int}, max_score: float|null}
     */
    public static function severitySummary(?object $row): array
    {
        $count = fn (string $column): int => (int) ($row->{$column} ?? 0);

        return [
            'findings' => [
                'critical' => $count('critical'),
                'high' => $count('high'),
                'medium' => $count('medium'),
                'low' => $count('low'),
                'none' => $count('none'),
                'total' => $count('total'),
            ],
            'max_score' => isset($row->max_score) ? (float) $row->max_score : null,
        ];
    }

    /**
     * The environment's installed copies of a finding's package version.
     */
    private static function installedCopies(): Builder
    {
        return DB::table('voight_environment_packages as ep')
            ->whereColumn('ep.environment_id', 'e.id')
            ->whereColumn('ep.package_id', 'f.package_id')
            ->whereColumn('ep.version', 'f.installed_version');
    }
}
