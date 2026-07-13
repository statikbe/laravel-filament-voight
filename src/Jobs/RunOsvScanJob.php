<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;

class RunOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public Environment $environment,
        public AuditRunTrigger $trigger = AuditRunTrigger::Manual,
    ) {}

    public function handle(OsvScannerClient $client, RecordEnvironmentAuditRunsService $recorder): void
    {
        $files = $this->resolveLockfileContents();

        if ($files === []) {
            $this->fail(new RuntimeException(
                "No completed dependency sync found for environment '{$this->environment->name}'. Run a lock file sync first."
            ));

            return;
        }

        try {
            $response = $client->scanLockfiles(
                $files,
                $this->environment->project->project_code,
                $this->environment->name,
            );

            $models = $recorder->upsertVulnerabilities($response->vulnerabilities, $response->maxSeverityById());
            $recorder->record(
                $this->environment,
                $response->findingsByPackageKey(),
                $models,
                $response->vulnerabilities,
                $this->trigger,
            );

            Log::info('[Voight] OSV scan completed', [
                'environment' => $this->environment->id,
                'trigger' => $this->trigger->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Voight] OSV scan failed', [
                'environment' => $this->environment->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            $this->recordFailedRun();

            throw $e;
        }
    }

    /**
     * Read the latest completed sync's lockfiles off disk into a name => contents map.
     *
     * @return array<string, string>
     */
    private function resolveLockfileContents(): array
    {
        $latestSync = $this->environment->dependencySyncs()
            ->where('status', DependencySyncStatus::Completed)
            ->latest()
            ->first();

        if (! $latestSync) {
            return [];
        }

        $disk = Storage::disk(FilamentVoight::config()->getLockfilesDisk());
        $scannable = ['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'];
        $files = [];

        foreach ($latestSync->lockfile_paths ?? [] as $path) {
            $filename = basename($path);

            if (! in_array($filename, $scannable, true)) {
                continue;
            }

            $content = $disk->get($path);

            if ($content) {
                $files[$filename] = $content;
            }
        }

        return $files;
    }

    private function recordFailedRun(): void
    {
        /** @var class-string<AuditRun> $auditRunModel */
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        $auditRunModel::create([
            'environment_id' => $this->environment->id,
            'status' => AuditRunStatus::Failed,
            'trigger' => $this->trigger,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
