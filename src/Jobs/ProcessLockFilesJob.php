<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Parsers\ComposerLockParser;
use Statikbe\FilamentVoight\Parsers\PackageLockParser;
use Statikbe\FilamentVoight\Parsers\UnsupportedLockfileException;
use Statikbe\FilamentVoight\Parsers\YarnLockParser;

class ProcessLockFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int> */
    public array $backoff = [10, 60, 180];

    /**
     * Manifests are uploaded next to lockfiles so parsers can detect direct
     * dependencies; they are never parsed on their own.
     *
     * @var array<int, string>
     */
    private const array MANIFEST_FILENAMES = ['composer.json', 'package.json'];

    /**
     * Warnings collected while parsing, stored on the sync when it completes.
     *
     * @var array<int, array{code: string, context: array<string, string>}>
     */
    private array $warnings = [];

    public function __construct(
        public DependencySync $sync,
    ) {}

    public function handle(): void
    {
        if (empty($this->sync->lockfile_paths)) {
            $this->fail(new RuntimeException("DependencySync {$this->sync->id} has no lockfile paths"));

            return;
        }

        $this->sync->update(['status' => DependencySyncStatus::Processing]);

        Log::info('[Voight] Lock file processing started', [
            'sync' => $this->sync->id,
            'environment' => $this->sync->environment_id,
            'lockfiles' => $this->sync->lockfile_paths,
        ]);

        $this->warnings = [];

        try {
            $parsedPackages = $this->parseLockFiles();

            Log::info('[Voight] Parsed lock files', [
                'sync' => $this->sync->id,
                'package_count' => count($parsedPackages),
            ]);

            DB::transaction(function () use ($parsedPackages) {
                $this->syncPackages($parsedPackages);
            });

            $this->sync->update([
                'status' => DependencySyncStatus::Completed,
                'package_count' => count($parsedPackages),
                'warnings' => $this->warnings ?: null,
                'synced_at' => now(),
            ]);

            $this->sync->environment->update(['scanned_at' => now()]);

            Log::info('[Voight] Lock file processing completed', [
                'sync' => $this->sync->id,
                'package_count' => count($parsedPackages),
            ]);

            RunOsvScanJob::dispatch($this->sync->environment, AuditRunTrigger::PostSync);
        } catch (\Throwable $e) {
            Log::error('[Voight] Lock file processing failed', [
                'sync' => $this->sync->id,
                'environment' => $this->sync->environment_id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            $this->sync->update([
                'status' => DependencySyncStatus::Failed,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>
     */
    private function parseLockFiles(): array
    {
        $disk = Storage::disk(FilamentVoight::config()->getLockfilesDisk());
        $packages = [];

        foreach ($this->sync->lockfile_paths ?? [] as $path) {
            $filename = basename($path);

            if (in_array($filename, self::MANIFEST_FILENAMES, true)) {
                continue;
            }

            $content = $disk->get($path);

            if (! $content) {
                Log::warning('[Voight] Lockfile not found on disk, skipping', [
                    'sync' => $this->sync->id,
                    'path' => $path,
                ]);
                $this->addWarning(SyncWarning::LockfileMissingOnDisk, $path);

                continue;
            }

            try {
                $parsed = match ($filename) {
                    'composer.lock' => (new ComposerLockParser)->parse($content),
                    'package-lock.json' => (new PackageLockParser)->parse($content),
                    'yarn.lock' => $this->parseYarnLock($path, $content, $disk),
                    default => null,
                };
            } catch (UnsupportedLockfileException $e) {
                $this->warnUnsupported($e->warning, $path);

                continue;
            }

            if ($parsed === null) {
                $this->warnUnsupported(SyncWarning::UnsupportedLockfile, $path);

                continue;
            }

            Log::debug('[Voight] Parsed lockfile', [
                'sync' => $this->sync->id,
                'file' => $filename,
                'packages_found' => count($parsed),
            ]);

            $packages = array_merge($packages, $parsed);
        }

        return $packages;
    }

    /**
     * @return array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>
     */
    private function parseYarnLock(string $path, string $content, Filesystem $disk): array
    {
        $packageJson = $this->findCompanionFile($path, 'package.json', $disk);

        if ($packageJson !== null && array_key_exists('workspaces', (array) json_decode($packageJson, true))) {
            $this->warnUnsupported(SyncWarning::YarnWorkspacesUnsupported, $path);
        }

        return (new YarnLockParser)->parse($content, $packageJson);
    }

    private function warnUnsupported(SyncWarning $warning, string $path): void
    {
        Log::warning('[Voight] Lockfile not fully supported', [
            'sync' => $this->sync->id,
            'path' => $path,
            'warning' => $warning->value,
        ]);

        $this->addWarning($warning, $path);
    }

    private function addWarning(SyncWarning $warning, string $path): void
    {
        $this->warnings[] = ['code' => $warning->value, 'context' => ['file' => basename($path)]];
    }

    /**
     * Look for a companion file (e.g. package.json) in the same directory as the given lockfile path.
     */
    private function findCompanionFile(string $lockfilePath, string $companionFilename, Filesystem $disk): ?string
    {
        $companionPath = dirname($lockfilePath) . '/' . $companionFilename;

        if (in_array($companionPath, $this->sync->lockfile_paths ?? [], true)) {
            return $disk->get($companionPath);
        }

        return null;
    }

    /**
     * @param  array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>  $parsedPackages
     */
    private function syncPackages(array $parsedPackages): void
    {
        $environmentId = $this->sync->environment_id;
        $environmentPackageModel = FilamentVoight::config()->getEnvironmentPackageModel();

        $environmentPackageModel::where('environment_id', $environmentId)->delete();

        $packageModels = $this->resolvePackageModels($parsedPackages);

        $rows = [];
        $now = now();

        foreach ($parsedPackages as $parsed) {
            $package = $packageModels[$parsed['name']];

            $rows[] = [
                'id' => Str::ulid()->toBase32(),
                'environment_id' => $environmentId,
                'package_id' => $package->id,
                'version' => $parsed['version'],
                'is_direct' => $parsed['is_direct'],
                'is_dev' => $parsed['is_dev'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $environmentPackageModel::insert($chunk);
        }
    }

    /**
     * @param  array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>  $parsedPackages
     * @return array<string, Package>
     */
    private function resolvePackageModels(array $parsedPackages): array
    {
        $uniquePackages = [];
        foreach ($parsedPackages as $parsed) {
            $uniquePackages[$parsed['name']] ??= $parsed['type'];
        }

        $packageModel = FilamentVoight::config()->getPackageModel();

        $existing = $packageModel::whereIn('name', array_keys($uniquePackages))
            ->get()
            ->keyBy('name');

        foreach ($uniquePackages as $name => $type) {
            if (! $existing->has($name)) {
                $existing[$name] = $packageModel::create(['name' => $name, 'type' => $type]);
            }
        }

        return $existing->all();
    }
}
