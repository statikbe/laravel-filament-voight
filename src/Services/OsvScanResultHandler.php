<?php

namespace Statikbe\FilamentVoight\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\RecordNightlySweepJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Support\ScanError;
use Statikbe\FilamentVoight\Support\ScanResponse;
use Statikbe\FilamentVoight\Support\ScanStatus;
use Throwable;

/**
 * Acts on a scan's terminal status. Callbacks and polling both end here; the
 * atomic claim makes exactly one of them record the outcome.
 */
class OsvScanResultHandler
{
    public function __construct(private RecordEnvironmentAuditRunsService $recorder) {}

    public function handleTerminal(OsvScan $scan, string $scannerScanId, ScanStatus $status): void
    {
        if (! $this->claim($scan, $scannerScanId)) {
            return;
        }

        $scan->refresh();

        if ($status->isDone() && $status->result !== null) {
            try {
                $scan->type === OsvScanType::Locks
                    ? $this->recordLocks($scan, $status->result)
                    : $this->completeBatch($scan);
            } catch (Throwable $e) {
                $this->markFailed($scan, 'Recording the scan result failed: ' . $e->getMessage());

                throw $e;
            }

            return;
        }

        $error = $status->error ?? new ScanError('unknown', 'Scanner reported a failure without details', true);

        if ($error->retryable && $scan->resubmits < FilamentVoight::config()->getScannerMaxResubmits() && $this->canResubmit($scan)) {
            $scan->update(['status' => OsvScanStatus::Pending, 'scanner_scan_id' => null, 'resubmits' => $scan->resubmits + 1]);
            SubmitOsvScanJob::dispatch($scan);

            return;
        }

        $this->markFailed($scan, "{$error->code}: {$error->message}");
    }

    /**
     * Fail a scan that can no longer finish (submission or polling gave up).
     * With $scannerScanId, only if that submission is still the current one.
     */
    public function failForGood(OsvScan $scan, string $message, ?string $scannerScanId = null): void
    {
        $query = $this->scanQuery()->whereKey($scan->id)->where('status', OsvScanStatus::Pending);

        if ($scannerScanId !== null) {
            $query->where('scanner_scan_id', $scannerScanId);
        }

        if ($query->update(['status' => OsvScanStatus::Processing]) === 0) {
            return;
        }

        $this->markFailed($scan->refresh(), $message);
    }

    /**
     * Fail a scan stranded pending or processing since before $staleBefore (its
     * poll job was lost, or a worker died after claiming it). The claim also
     * requires the row to be unchanged since then, so a completion that claimed
     * it in the meantime wins. Returns whether this call failed the scan.
     */
    public function failAbandoned(OsvScan $scan, CarbonInterface $staleBefore, string $message): bool
    {
        $claimed = $this->scanQuery()
            ->whereKey($scan->id)
            ->whereIn('status', [OsvScanStatus::Pending, OsvScanStatus::Processing])
            ->where('updated_at', '<=', $staleBefore)
            ->update(['status' => OsvScanStatus::Processing]);

        if ($claimed === 0) {
            return false;
        }

        $this->markFailed($scan->refresh(), $message);

        return true;
    }

    private function claim(OsvScan $scan, string $scannerScanId): bool
    {
        return $this->scanQuery()
            ->whereKey($scan->id)
            ->where('scanner_scan_id', $scannerScanId)
            ->where('status', OsvScanStatus::Pending)
            ->update(['status' => OsvScanStatus::Processing]) === 1;
    }

    private function recordLocks(OsvScan $scan, ScanResponse $result): void
    {
        // One transaction: a failure halfway leaves no Running audit run behind.
        $auditRun = DB::transaction(function () use ($scan, $result): ?AuditRun {
            $environment = $scan->environment;
            $auditRun = null;

            if ($environment) {
                $models = $this->recorder->upsertVulnerabilities($result->vulnerabilities, $result->maxSeverityById());
                $auditRun = $this->recorder->record($environment, $result->findingsByPackageKey(), $models, $result->vulnerabilities, $scan->trigger);
            }

            $this->finish($scan, OsvScanStatus::Completed);

            return $auditRun;
        });

        if ($auditRun) {
            SendAuditAlertsJob::dispatch($auditRun);
        }
    }

    private function completeBatch(OsvScan $scan): void
    {
        // Committed on its own (no wrapping transaction) so concurrent batch
        // completions see each other in the count below.
        $this->finish($scan, OsvScanStatus::Completed);

        $sweep = $scan->nightlySweep;

        if (! $sweep || $sweep->scans()->where('status', '!=', OsvScanStatus::Completed)->exists()) {
            return;
        }

        $claimed = $this->sweepQuery()
            ->whereKey($sweep->id)
            ->where('status', NightlySweepStatus::Scanning)
            ->update(['status' => NightlySweepStatus::Recording]);

        if ($claimed === 1) {
            RecordNightlySweepJob::dispatch($sweep->refresh());
        }
    }

    private function markFailed(OsvScan $scan, string $message): void
    {
        $this->finish($scan, OsvScanStatus::Failed, $message);

        if ($scan->type === OsvScanType::Locks) {
            if ($scan->environment_id !== null) {
                $this->recorder->recordFailedForEnvironments([$scan->environment_id], $scan->trigger, $message);
            }

            return;
        }

        $sweep = $scan->nightlySweep;

        if (! $sweep) {
            return;
        }

        // Only the first failing batch fails the sweep and records the failed runs.
        $claimed = $this->sweepQuery()
            ->whereKey($sweep->id)
            ->where('status', NightlySweepStatus::Scanning)
            ->update(['status' => NightlySweepStatus::Failed, 'completed_at' => now()]);

        if ($claimed === 1) {
            $this->recorder->recordFailedForEnvironments($sweep->environment_ids, AuditRunTrigger::Nightly, 'Nightly scan batch failed: ' . $message);
        }
    }

    private function canResubmit(OsvScan $scan): bool
    {
        return $scan->type === OsvScanType::Locks
            || $scan->nightlySweep?->status === NightlySweepStatus::Scanning;
    }

    private function finish(OsvScan $scan, OsvScanStatus $status, ?string $message = null): void
    {
        if ($scan->input_path !== null) {
            Storage::disk(FilamentVoight::config()->getLockfilesDisk())->delete($scan->input_path);
        }

        $scan->update([
            'status' => $status,
            'error_message' => $message === null ? null : mb_substr($message, 0, 500),
            'input_path' => null,
        ]);
    }

    /**
     * @return Builder<OsvScan>
     */
    private function scanQuery(): Builder
    {
        /** @var class-string<OsvScan> $model */
        $model = FilamentVoight::config()->getOsvScanModel();

        return $model::query();
    }

    /**
     * @return Builder<NightlySweep>
     */
    private function sweepQuery(): Builder
    {
        /** @var class-string<NightlySweep> $model */
        $model = FilamentVoight::config()->getNightlySweepModel();

        return $model::query();
    }
}
