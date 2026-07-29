<?php

namespace Statikbe\FilamentVoight\Notifications;

use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
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
        public string $detailLabel,
        public Carbon $generatedAt,
    ) {}

    public static function fromAuditRun(AuditRun $auditRun, float $severityThreshold): self
    {
        return self::fromRunFindings($auditRun, self::findingsForRun($auditRun, $severityThreshold));
    }

    /**
     * The run's findings at or above the threshold, ready to be narrowed down
     * before a summary is built from them.
     *
     * @return Collection<int, AuditFinding>
     */
    public static function findingsForRun(AuditRun $auditRun, float $severityThreshold): Collection
    {
        return $auditRun->auditFindings()
            ->whereHas('vulnerability', fn (Builder $query): Builder => $query->where('vulnerability_score', '>=', $severityThreshold))
            ->with(['vulnerability', 'package'])
            ->get();
    }

    /**
     * Summarise an explicit subset of a run's findings.
     *
     * @param  Collection<int, AuditFinding>  $findings
     */
    public static function fromRunFindings(AuditRun $auditRun, Collection $findings): self
    {
        return self::build(
            $auditRun->environment->project,
            [$auditRun->environment->name],
            $findings,
            self::alertsPanelUrl(AuditRunResource::class, $auditRun),
            voightTrans('notifications.common.view_audit_run'),
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

        // A digest summarises the latest run of every environment, so there is no
        // single run it could honestly link to.
        return self::build(
            $project,
            $environmentNames,
            $findings,
            self::alertsPanelUrl(ProjectResource::class, $project),
            voightTrans('notifications.common.view_project'),
        );
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
     * Absolute link into the panel that sends the alerts, since notifications are rendered off-request.
     *
     * @param  class-string<FilamentResource>  $resource
     */
    private static function alertsPanelUrl(string $resource, Model $record): string
    {
        return $resource::getUrl(
            'view',
            ['record' => $record],
            isAbsolute: true,
            panel: FilamentVoight::config()->getAlertsPanelId(),
        );
    }

    /**
     * @param  array<string>  $environmentNames
     * @param  Collection<int, AuditFinding>  $findings
     * @param  string  $detailUrl  Where this particular summary came from — a run for immediate alerts, the project for digests.
     * @param  string  $detailLabel  Call to action matching $detailUrl.
     */
    private static function build(
        Project $project,
        array $environmentNames,
        Collection $findings,
        string $detailUrl,
        string $detailLabel,
    ): self {
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
            detailUrl: $detailUrl,
            detailLabel: $detailLabel,
            generatedAt: now(),
        );
    }
}
