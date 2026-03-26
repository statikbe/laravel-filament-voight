<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Events\EnvironmentCreatedViaApi;
use Statikbe\FilamentVoight\Events\ProjectCreatedViaApi;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

class LockFileSyncService
{
    /**
     * @param array<string, UploadedFile> $lockfiles
     */
    public function sync(string $projectCode, string $environmentName, array $lockfiles, ?Project $project = null): DependencySync
    {
        $project = $project ?? $this->resolveProject($projectCode);
        $environment = $this->resolveEnvironment($project, $environmentName);

        $storedPaths = $this->storeLockFiles($project, $environment, $lockfiles);
        $lockfileHash = $this->computeHash($lockfiles);

        $sync = DependencySync::create([
            'environment_id' => $environment->id,
            'lockfile_hash' => $lockfileHash,
            'lockfile_paths' => $storedPaths,
            'status' => DependencySyncStatus::Pending,
        ]);

        ProcessLockFilesJob::dispatch($sync);

        return $sync;
    }

    private function resolveProject(string $projectCode): Project
    {
        $project = Project::firstOrCreate(
            ['project_code' => $projectCode],
        );

        if ($project->wasRecentlyCreated) {
            ProjectCreatedViaApi::dispatch($project);
        }

        return $project;
    }

    private function resolveEnvironment(Project $project, string $environmentName): Environment
    {
        $environment = Environment::firstOrCreate(
            ['project_id' => $project->id, 'name' => $environmentName],
        );

        if ($environment->wasRecentlyCreated) {
            EnvironmentCreatedViaApi::dispatch($environment);
        }

        return $environment;
    }

    /**
     * @param array<string, UploadedFile> $lockfiles
     * @return array<string>
     */
    private function storeLockFiles(Project $project, Environment $environment, array $lockfiles): array
    {
        $disk = Storage::disk(FilamentVoight::config()->getLockfilesDisk());
        $basePath = "{$project->project_code}/{$environment->name}";
        $storedPaths = [];

        foreach ($lockfiles as $file) {
            $path = "{$basePath}/{$file->getClientOriginalName()}";
            $disk->put($path, $file->getContent());
            $storedPaths[] = $path;
        }

        return $storedPaths;
    }

    /**
     * @param array<string, UploadedFile> $lockfiles
     */
    private function computeHash(array $lockfiles): string
    {
        $contents = array_map(fn (UploadedFile $file): string => $file->getContent(), $lockfiles);

        return hash('sha256', implode('', $contents));
    }
}
