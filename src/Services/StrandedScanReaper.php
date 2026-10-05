<?php

namespace Statikbe\FilamentVoight\Services;

use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;

/**
 * Fails scans and nightly sweeps that can no longer finish on their own: a lost
 * poll job, a worker killed after claiming a scan, or a recording job that was
 * never dispatched. Every claim is atomic, so racing a real completion is safe.
 */
class StrandedScanReaper
{
    public function __construct(
        private OsvScanResultHandler $handler,
        private RecordEnvironmentAuditRunsService $recorder,
    ) {}

    /**
     * Scans are reaped first: a failed batch fails its sweep through the normal
     * batch failure path, before the longer sweep threshold applies.
     *
     * @return array{scans: int, sweeps: int}
     */
    public function reap(): array
    {
        return ['scans' => $this->reapScans(), 'sweeps' => $this->reapSweeps()];
    }

    private function reapScans(): int
    {
        $minutes = FilamentVoight::config()->getScannerReapScansAfterMinutes();
        $staleBefore = now()->subMinutes($minutes);

        /** @var class-string<OsvScan> $scanModel */
        $scanModel = FilamentVoight::config()->getOsvScanModel();

        $stranded = $scanModel::query()
            ->whereIn('status', [OsvScanStatus::Pending, OsvScanStatus::Processing])
            ->where('updated_at', '<=', $staleBefore)
            ->get();

        return $stranded
            ->filter(fn (OsvScan $scan): bool => $this->handler->failAbandoned($scan, $staleBefore, "scan abandoned: no result after {$minutes} minutes"))
            ->count();
    }

    private function reapSweeps(): int
    {
        $minutes = FilamentVoight::config()->getScannerReapSweepsAfterMinutes();
        $staleBefore = now()->subMinutes($minutes);
        $unfinished = [NightlySweepStatus::Scanning, NightlySweepStatus::Recording];

        /** @var class-string<NightlySweep> $sweepModel */
        $sweepModel = FilamentVoight::config()->getNightlySweepModel();

        $reaped = 0;

        foreach ($sweepModel::query()->whereIn('status', $unfinished)->where('updated_at', '<=', $staleBefore)->get() as $sweep) {
            // Same claim shape as the batch and recording failure paths: one winner.
            $claimed = $sweepModel::query()
                ->whereKey($sweep->id)
                ->whereIn('status', $unfinished)
                ->where('updated_at', '<=', $staleBefore)
                ->update(['status' => NightlySweepStatus::Failed, 'completed_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $this->recorder->recordFailedForEnvironments(
                $sweep->environment_ids,
                AuditRunTrigger::Nightly,
                "nightly sweep abandoned: not finished after {$minutes} minutes",
            );
            $reaped++;
        }

        return $reaped;
    }
}
