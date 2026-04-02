<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Parsers\ComposerLockParser;
use Statikbe\FilamentVoight\Parsers\PackageLockParser;
use Statikbe\FilamentVoight\Parsers\YarnLockParser;

class ProcessLockFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public DependencySync $sync,
    ) {}

    public function handle(): void
    {
        $this->sync->update(['status' => DependencySyncStatus::Processing]);

        try {
            $parsedPackages = $this->parseLockFiles();

            DB::transaction(function () use ($parsedPackages) {
                $this->syncPackages($parsedPackages);
            });

            $this->sync->update([
                'status' => DependencySyncStatus::Completed,
                'package_count' => count($parsedPackages),
                'synced_at' => now(),
            ]);

            $this->sync->environment->update(['scanned_at' => now()]);

            RunOsvScanJob::dispatch($this->sync->environment);
        } catch (\Throwable $e) {
            $this->sync->update([
                'status' => DependencySyncStatus::Failed,
                'error_message' => $e->getMessage(),
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

            $packages = array_merge($packages, $parsed);
        }

        return $packages;
    }

    /**
     * Look for a companion file (e.g. package.json) in the same directory as the given lockfile path.
     */
    private function findCompanionFile(string $lockfilePath, string $companionFilename, \Illuminate\Contracts\Filesystem\Filesystem $disk): ?string
    {
        $directory = dirname($lockfilePath);
        $companionPath = $directory . '/' . $companionFilename;

        // Check if it was uploaded alongside the lockfile
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

        $packageModels = [];

        foreach ($parsedPackages as $parsed) {
            $package = Package::firstOrCreate(
                ['name' => $parsed['name'], 'type' => $parsed['type']],
            );
            $packageModels[$parsed['name']] = $package;
        }

        foreach ($parsedPackages as $parsed) {
            $package = $packageModels[$parsed['name']];

            $parentPackageId = null;
            if (! $parsed['is_direct']) {
                foreach ($parsedPackages as $potentialParent) {
                    if (in_array($parsed['name'], $potentialParent['require'], true)) {
                        $parentPackageId = $packageModels[$potentialParent['name']]->id ?? null;

                        break;
                    }
                }
            }

            EnvironmentPackage::create([
                'environment_id' => $environmentId,
                'package_id' => $package->id,
                'version' => $parsed['version'],
                'is_direct' => $parsed['is_direct'],
                'is_dev' => $parsed['is_dev'],
                'parent_package_id' => $parentPackageId,
            ]);
        }
    }
}
