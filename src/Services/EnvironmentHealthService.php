<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Support\EnvironmentIssue;

/**
 * Derives an environment's current problems from its latest finished sync and
 * scan. Nothing is stored: an issue disappears once a newer sync or scan succeeds.
 */
class EnvironmentHealthService
{
    /**
     * Eager load Environment::HEALTH_RELATIONS to avoid a query per environment.
     *
     * @return Collection<int, EnvironmentIssue> worst first
     */
    public function issuesFor(Environment $environment): Collection
    {
        $environment->loadMissing(Environment::HEALTH_RELATIONS);

        return collect([
            ...$this->syncIssues($environment->latestFinishedDependencySync),
            ...$this->scanIssues($environment->latestFinishedAuditRun),
        ])
            ->sortBy(fn (EnvironmentIssue $issue): int => $issue->type->priority())
            ->values();
    }

    /**
     * @return array<int, EnvironmentIssue>
     */
    private function syncIssues(?DependencySync $sync): array
    {
        if ($sync === null) {
            return [new EnvironmentIssue(EnvironmentIssueType::NeverSynced, EnvironmentIssueType::NeverSynced->getLabel())];
        }

        if ($sync->status === DependencySyncStatus::Failed) {
            return [$this->failure(EnvironmentIssueType::SyncFailed, $sync->error_message, $sync->updated_at)];
        }

        $issues = [];

        foreach ($sync->warnings ?? [] as $warning) {
            $syncWarning = SyncWarning::tryFrom($warning['code']);

            if ($syncWarning !== null) {
                $issues[] = new EnvironmentIssue(EnvironmentIssueType::SyncWarning, $syncWarning->message($warning['context']), $sync->synced_at);
            }
        }

        return $issues;
    }

    /**
     * @return array<int, EnvironmentIssue>
     */
    private function scanIssues(?AuditRun $auditRun): array
    {
        if ($auditRun?->status !== AuditRunStatus::Failed) {
            return [];
        }

        return [$this->failure(EnvironmentIssueType::ScanFailed, $auditRun->error_message, $auditRun->completed_at)];
    }

    /**
     * Falls back to the issue type's label when no error message was stored.
     */
    private function failure(EnvironmentIssueType $type, ?string $errorMessage, ?Carbon $occurredAt): EnvironmentIssue
    {
        return new EnvironmentIssue($type, $errorMessage ?: $type->getLabel(), $occurredAt);
    }
}
