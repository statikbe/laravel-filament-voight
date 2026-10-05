<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

/**
 * Starts an asynchronous lockfile scan for one environment. The outcome is
 * recorded later by OsvScanResultHandler, once the scanner has finished.
 */
class RunOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(
        public Environment $environment,
        public AuditRunTrigger $trigger = AuditRunTrigger::Manual,
    ) {}

    public function handle(OsvScanSubmitter $submitter): void
    {
        $files = $this->resolveLockfileContents();

        if ($files === []) {
            $this->fail(new RuntimeException(
                "No completed dependency sync found for environment '{$this->environment->name}'. Run a lock file sync first."
            ));

            return;
        }

        $input = json_encode([
            'files' => collect($files)
                ->map(fn (string $contents, string $name): array => ['name' => $name, 'content_base64' => base64_encode($contents)])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR);

        $submitter->start(
            OsvScanType::Locks,
            $input,
            ['project_code' => $this->environment->project->project_code, 'environment' => $this->environment->name],
            $this->trigger,
            environmentId: $this->environment->id,
        );
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
}
