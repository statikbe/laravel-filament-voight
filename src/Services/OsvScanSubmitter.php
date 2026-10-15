<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\OsvScan;

class OsvScanSubmitter
{
    /**
     * Create the scan and queue its submission. The input is saved so a failed
     * scan can be resubmitted without rebuilding it.
     *
     * @param  array<string, mixed>  $meta  echoed by the scanner; batch_id or project_code + environment
     */
    public function start(
        OsvScanType $type,
        string $input,
        array $meta,
        AuditRunTrigger $trigger,
        ?string $environmentId = null,
        ?string $nightlySweepId = null,
    ): OsvScan {
        /** @var class-string<OsvScan> $scanModel */
        $scanModel = FilamentVoight::config()->getOsvScanModel();

        // One transaction: a scan whose input could not be saved is not left behind.
        $scan = DB::transaction(function () use ($scanModel, $type, $input, $meta, $trigger, $environmentId, $nightlySweepId): OsvScan {
            $scan = $scanModel::create([
                'type' => $type,
                'status' => OsvScanStatus::Pending,
                'environment_id' => $environmentId,
                'nightly_sweep_id' => $nightlySweepId,
                'trigger' => $trigger,
                'meta' => $meta,
            ]);

            $path = "voight/scans/{$scan->id}/input.json";

            if (! Storage::disk(FilamentVoight::config()->getLockfilesDisk())->put($path, $input)) {
                throw new RuntimeException("Could not save the scan input to {$path}");
            }

            $scan->update(['input_path' => $path]);

            return $scan;
        });

        // After commit: a nightly sweep creates all its batches in one transaction.
        SubmitOsvScanJob::dispatch($scan)->afterCommit();

        return $scan;
    }
}
