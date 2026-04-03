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
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use RuntimeException;
use Statikbe\FilamentVoight\Parsers\ComposerLockParser;
use Statikbe\FilamentVoight\Parsers\PackageLockParser;
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
                'synced_at' => now(),
            ]);

            $this->sync->environment->update(['scanned_at' => now()]);

            Log::info('[Voight] Lock file processing completed', [
                'sync' => $this->sync->id,
                'package_count' => count($parsedPackages),
            ]);

            RunOsvScanJob::dispatch($this->sync->environment);
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
            $content = $disk->get($path);

            if (! $content) {
                Log::warning('[Voight] Lockfile not found on disk, skipping', [
                    'sync' => $this->sync->id,
                    'path' => $path,
                ]);

                continue;
            }

            $filename = basename($path);

            $parsed = match ($filename) {
                'composer.lock' => (new ComposerLockParser)->parse($content),
                'package-lock.json' => (new PackageLockParser)->parse($content),
                'yarn.lock' => (new YarnLockParser)->parse(
                    $content,
                    $this->findCompanionFile($path, 'package.json', $disk),
                ),
                default => [],
            };

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

        EnvironmentPackage::where('environment_id', $environmentId)->delete();

        $packageModels = $this->resolvePackageModels($parsedPackages);
        $dependedBy = $this->buildReverseDependencyMap($parsedPackages);

        $rows = [];
        $now = now();

        foreach ($parsedPackages as $parsed) {
            $package = $packageModels[$parsed['name']];
            $parentName = ! $parsed['is_direct'] ? ($dependedBy[$parsed['name']] ?? null) : null;

            $rows[] = [
                'id' => Str::ulid()->toBase32(),
                'environment_id' => $environmentId,
                'package_id' => $package->id,
                'version' => $parsed['version'],
                'is_direct' => $parsed['is_direct'],
                'is_dev' => $parsed['is_dev'],
                'parent_package_id' => $parentName ? ($packageModels[$parentName]->id ?? null) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            EnvironmentPackage::insert($chunk);
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

        $existing = Package::whereIn('name', array_keys($uniquePackages))
            ->get()
            ->keyBy('name');

        foreach ($uniquePackages as $name => $type) {
            if (! $existing->has($name)) {
                $existing[$name] = Package::create(['name' => $name, 'type' => $type]);
            }
        }

        return $existing->all();
    }

    /**
     * @param  array<int, array{name: string, version: string, type: PackageType, is_direct: bool, is_dev: bool, require: array<string>}>  $parsedPackages
     * @return array<string, string>
     */
    private function buildReverseDependencyMap(array $parsedPackages): array
    {
        $dependedBy = [];

        foreach ($parsedPackages as $parsed) {
            foreach ($parsed['require'] as $requiredName) {
                $dependedBy[$requiredName] ??= $parsed['name'];
            }
        }

        return $dependedBy;
    }
}
