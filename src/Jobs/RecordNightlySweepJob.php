<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;
use Throwable;

/**
 * Records a nightly sweep once every package batch has completed: merges the
 * batch results and records one AuditRun per snapshotted environment.
 */
class RecordNightlySweepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public NightlySweep $sweep) {}

    public function handle(OsvScannerClient $client, RecordEnvironmentAuditRunsService $recorder): void
    {
        $findingsMap = [];
        $vulnerabilities = [];
        $maxSeverityById = [];

        // Fetch everything before writing anything: a missing batch must not
        // leave half the environments recorded.
        foreach ($this->sweep->scans()->get() as $scan) {
            $status = $client->getScan((string) $scan->scanner_scan_id);

            if (! $status->isDone() || $status->result === null) {
                throw new RuntimeException("Nightly batch {$scan->id} has no result to record (scanner status: {$status->status})");
            }

            $findingsMap = $this->mergeFindings($findingsMap, $status->result->findingsByPackageKey());
            $vulnerabilities += $status->result->vulnerabilities;
            $maxSeverityById = $this->mergeMaxSeverity($maxSeverityById, $status->result->maxSeverityById());
        }

        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        /** @var class-string<NightlySweep> $sweepModel */
        $sweepModel = FilamentVoight::config()->getNightlySweepModel();

        DB::transaction(function () use ($recorder, $environmentModel, $sweepModel, $findingsMap, $vulnerabilities, $maxSeverityById): void {
            // A duplicate run (retry_after expiring while this one still runs) must not record twice.
            $sweep = $sweepModel::query()->whereKey($this->sweep->id)->lockForUpdate()->first();

            if ($sweep?->status !== NightlySweepStatus::Recording) {
                return;
            }

            $models = $recorder->upsertVulnerabilities($vulnerabilities, $maxSeverityById);

            foreach ($environmentModel::query()->whereKey($this->sweep->environment_ids)->get() as $environment) {
                $recorder->record($environment, $findingsMap, $models, $vulnerabilities, AuditRunTrigger::Nightly);
            }

            $sweep->update(['status' => NightlySweepStatus::Completed, 'completed_at' => now()]);
        });
    }

    public function failed(Throwable $e): void
    {
        /** @var class-string<NightlySweep> $sweepModel */
        $sweepModel = FilamentVoight::config()->getNightlySweepModel();

        $claimed = $sweepModel::query()
            ->whereKey($this->sweep->id)
            ->where('status', NightlySweepStatus::Recording)
            ->update(['status' => NightlySweepStatus::Failed, 'completed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        app(RecordEnvironmentAuditRunsService::class)->recordFailedForEnvironments(
            $this->sweep->environment_ids,
            AuditRunTrigger::Nightly,
            'Recording the nightly scan failed: ' . $e->getMessage(),
        );
    }

    /**
     * @param  array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>  $into
     * @param  array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>  $from
     * @return array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>
     */
    private function mergeFindings(array $into, array $from): array
    {
        foreach ($from as $key => $findings) {
            $into[$key] = array_merge($into[$key] ?? [], $findings);
        }

        return $into;
    }

    /**
     * @param  array<string, string|null>  $into
     * @param  array<string, string|null>  $from
     * @return array<string, string|null>
     */
    private function mergeMaxSeverity(array $into, array $from): array
    {
        foreach ($from as $id => $severity) {
            if (! array_key_exists($id, $into)) {
                $into[$id] = $severity;

                continue;
            }

            if ($severity !== null && (float) $severity > (float) ($into[$id] ?? 0)) {
                $into[$id] = $severity;
            }
        }

        return $into;
    }
}
