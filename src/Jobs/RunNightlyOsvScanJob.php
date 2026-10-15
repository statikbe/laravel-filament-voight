<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;
use Throwable;

class RunNightlyOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // A retry after the sweep committed would create a second sweep.
    public int $tries = 1;

    public int $timeout = 120;

    public function handle(OsvScanSubmitter $submitter): void
    {
        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        $environments = $environmentModel::query()->where('scan_nightly', true)->get();

        if ($environments->isEmpty()) {
            Log::info('[Voight] Nightly OSV scan: no environments in scope');

            return;
        }

        [$dedupeEnvironments, $lockfileEnvironments] = $this->partitionEnvironments($environments);

        $this->scanDeduplicated($dedupeEnvironments, $submitter);
        $this->scanCommitPinned($lockfileEnvironments);
    }

    /**
     * Called when this job itself fails (it is not retried). Creating the sweep is
     * atomic, so a failure here means no sweep exists and commit-pinned scans were
     * not dispatched; every in-scope environment gets a failed run explaining its
     * missing scan. Failures of the submitted batches are handled per scan.
     */
    public function failed(Throwable $e): void
    {
        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();
        /** @var class-string<AuditRun> $auditRunModel */
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        foreach ($environmentModel::query()->where('scan_nightly', true)->get() as $environment) {
            $auditRunModel::create([
                'environment_id' => $environment->id,
                'status' => AuditRunStatus::Failed,
                'trigger' => AuditRunTrigger::Nightly,
                'started_at' => now(),
                'completed_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * Split environments: any environment containing a commit-pinned (non-PURL-able)
     * package is scanned in full via /locks; the rest go through the deduplicated
     * /packages path. This subsumes per-package skipping and avoids double runs.
     *
     * @param  Collection<int, Environment>  $environments
     * @return array{0: Collection<int, Environment>, 1: Collection<int, Environment>}
     */
    private function partitionEnvironments(Collection $environments): array
    {
        /** @var class-string<EnvironmentPackage> $environmentPackageModel */
        $environmentPackageModel = FilamentVoight::config()->getEnvironmentPackageModel();

        $lockfileEnvironmentIds = $environmentPackageModel::query()
            ->whereIn('environment_id', $environments->pluck('id'))
            ->where(function (Builder $query): void {
                $query->where('version', 'like', 'dev-%')->orWhere('version', 'like', '%-dev');
            })
            ->distinct()
            ->pluck('environment_id')
            ->all();

        $lockfile = $environments->filter(fn (Environment $e): bool => in_array($e->id, $lockfileEnvironmentIds, true))->values();
        $dedupe = $environments->reject(fn (Environment $e): bool => in_array($e->id, $lockfileEnvironmentIds, true))->values();

        return [$dedupe, $lockfile];
    }

    /**
     * Create the sweep and all of its batches in one transaction: a sweep with
     * missing batches could otherwise "complete" and record a false clean result.
     * The submissions are queued after commit (see OsvScanSubmitter).
     *
     * @param  Collection<int, Environment>  $environments
     */
    private function scanDeduplicated(Collection $environments, OsvScanSubmitter $submitter): void
    {
        if ($environments->isEmpty()) {
            return;
        }

        /** @var class-string<EnvironmentPackage> $environmentPackageModel */
        $environmentPackageModel = FilamentVoight::config()->getEnvironmentPackageModel();
        $packages = $environmentPackageModel::distinctPackageSetForEnvironments($environments);

        if ($packages->isEmpty()) {
            return;
        }

        /** @var class-string<NightlySweep> $sweepModel */
        $sweepModel = FilamentVoight::config()->getNightlySweepModel();
        $batchSize = FilamentVoight::config()->getScannerBatchSize();

        DB::transaction(function () use ($environments, $packages, $sweepModel, $batchSize, $submitter): void {
            $sweep = $sweepModel::create([
                'status' => NightlySweepStatus::Scanning,
                'environment_ids' => $environments->pluck('id')->values()->all(),
                'started_at' => now(),
            ]);

            foreach ($packages->chunk($batchSize) as $chunk) {
                $batchId = (string) Str::ulid();
                $input = json_encode([
                    'batch_id' => $batchId,
                    'packages' => $chunk->map(fn (array $package): array => [
                        'ecosystem' => $this->ecosystem($package['type']),
                        'name' => $package['name'],
                        'version' => $package['version'],
                    ])->values()->all(),
                ], JSON_THROW_ON_ERROR);

                $submitter->start(OsvScanType::Packages, $input, ['batch_id' => $batchId], AuditRunTrigger::Nightly, nightlySweepId: $sweep->id);
            }
        });
    }

    /**
     * @param  Collection<int, Environment>  $environments
     */
    private function scanCommitPinned(Collection $environments): void
    {
        foreach ($environments as $environment) {
            RunOsvScanJob::dispatch($environment, AuditRunTrigger::Nightly);
        }

        if ($environments->isNotEmpty()) {
            Log::info('[Voight] Nightly OSV scan: routed commit-pinned environments to /locks', [
                'count' => $environments->count(),
            ]);
        }
    }

    private function ecosystem(PackageType $type): string
    {
        return $type === PackageType::Npm ? 'npm' : 'Packagist';
    }
}
