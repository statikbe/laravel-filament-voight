<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;

class RunNightlyOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public function handle(OsvScannerClient $client, RecordEnvironmentAuditRunsService $recorder): void
    {
        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        $environments = $environmentModel::query()->where('scan_nightly', true)->get();

        if ($environments->isEmpty()) {
            Log::info('[Voight] Nightly OSV scan: no environments in scope');

            return;
        }

        [$dedupeEnvironments, $lockfileEnvironments] = $this->partitionEnvironments($environments);

        $this->scanDeduplicated($dedupeEnvironments, $client, $recorder);
        $this->scanCommitPinned($lockfileEnvironments);
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
     * @param  Collection<int, Environment>  $environments
     */
    private function scanDeduplicated(
        Collection $environments,
        OsvScannerClient $client,
        RecordEnvironmentAuditRunsService $recorder,
    ): void {
        if ($environments->isEmpty()) {
            return;
        }

        /** @var class-string<EnvironmentPackage> $environmentPackageModel */
        $environmentPackageModel = FilamentVoight::config()->getEnvironmentPackageModel();
        $packages = $environmentPackageModel::distinctPackageSetForEnvironments($environments);

        if ($packages->isEmpty()) {
            return;
        }

        $findingsMap = [];
        $vulnerabilities = [];
        $maxSeverityById = [];

        $batchSize = FilamentVoight::config()->getScannerBatchSize();

        foreach ($packages->chunk($batchSize) as $chunk) {
            $payload = $chunk->map(fn (array $package): array => [
                'ecosystem' => $this->ecosystem($package['type']),
                'name' => $package['name'],
                'version' => $package['version'],
            ])->values()->all();

            // A chunk failure throws; $tries/$backoff retry the whole job. We never
            // record AuditRuns from a partial map — fan-out runs only after every
            // chunk has succeeded.
            $response = $client->scanPackages($payload, (string) Str::ulid());

            $findingsMap = $this->mergeFindings($findingsMap, $response->findingsByPackageKey());
            $vulnerabilities += $response->vulnerabilities;
            $maxSeverityById = $this->mergeMaxSeverity($maxSeverityById, $response->maxSeverityById());
        }

        $models = $recorder->upsertVulnerabilities($vulnerabilities, $maxSeverityById);

        foreach ($environments as $environment) {
            $recorder->record($environment, $findingsMap, $models, $vulnerabilities, AuditRunTrigger::Nightly);
        }
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
