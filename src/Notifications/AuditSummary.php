<?php

namespace Statikbe\FilamentVoight\Notifications;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource;

final readonly class AuditSummary
{
    /**
     * @param  array<string>  $environmentNames
     * @param  array<string, int>  $severityCounts  keyed by Severity value, critical first, zero buckets omitted
     * @param  array<int, array{package: string, summary: string, severity: Severity, score: float, installed_version: string|null, fixed_version: string|null}>  $topFindings
     */
    public function __construct(
        public string $projectName,
        public string $projectCode,
        public array $environmentNames,
        public array $severityCounts,
        public int $totalFindings,
        public array $topFindings,
        public string $detailUrl,
        public Carbon $generatedAt,
    ) {}

    public static function fromAuditRun(AuditRun $auditRun, float $severityThreshold): self
    {
        $findings = $auditRun->auditFindings()
            ->whereHas('vulnerability', fn (Builder $query): Builder => $query->where('vulnerability_score', '>=', $severityThreshold))
            ->with(['vulnerability', 'package'])
            ->get();

        return self::build(
            $auditRun->environment->project,
            [$auditRun->environment->name],
            $findings,
        );
    }

    public static function fromProjectOutstanding(Project $project, float $severityThreshold): self
    {
        $findings = $project->findings()
            ->whereIn('voight_audit_findings.audit_run_id', AuditRun::latestIdsPerEnvironment())
            ->whereHas('vulnerability', fn (Builder $query): Builder => $query->where('vulnerability_score', '>=', $severityThreshold))
            ->with(['vulnerability', 'package', 'auditRun.environment'])
            ->get();

        $environmentNames = $findings
            ->map(fn (AuditFinding $finding): string => $finding->auditRun->environment->name)
            ->unique()
            ->values()
            ->all();

        return self::build($project, $environmentNames, $findings);
    }

    public function hasFindings(): bool
    {
        return $this->totalFindings > 0;
    }

    public function environmentList(): string
    {
        return implode(', ', $this->environmentNames);
    }

    /**
     * @param  array<string>  $environmentNames
     * @param  Collection<int, AuditFinding>  $findings
     */
    private static function build(Project $project, array $environmentNames, Collection $findings): self
    {
        $severityCounts = [];

        foreach ([Severity::Critical, Severity::High, Severity::Medium, Severity::Low, Severity::None] as $severity) {
            $count = $findings
                ->filter(fn (AuditFinding $finding): bool => $finding->vulnerability->severity === $severity)
                ->count();

            if ($count > 0) {
                $severityCounts[$severity->value] = $count;
            }
        }

        $topFindings = $findings
            ->sortByDesc(fn (AuditFinding $finding): float => (float) $finding->vulnerability->vulnerability_score)
            ->take(5)
            ->map(fn (AuditFinding $finding): array => [
                'package' => $finding->package->name,
                'summary' => $finding->vulnerability->summary,
                'severity' => $finding->vulnerability->severity,
                'score' => (float) $finding->vulnerability->vulnerability_score,
                'installed_version' => $finding->installed_version,
                'fixed_version' => $finding->fixed_version,
            ])
            ->values()
            ->all();

        return new self(
            projectName: $project->name ?? $project->project_code,
            projectCode: $project->project_code,
            environmentNames: $environmentNames,
            severityCounts: $severityCounts,
            totalFindings: $findings->count(),
            topFindings: $topFindings,
            detailUrl: ProjectResource::getUrl(
                'view',
                ['record' => $project],
                isAbsolute: true,
                panel: FilamentVoight::config()->getAlertsPanelId(),
            ),
            generatedAt: now(),
        );
    }
}
