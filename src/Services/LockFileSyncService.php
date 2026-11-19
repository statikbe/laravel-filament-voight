<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Events\EnvironmentCreatedViaApi;
use Statikbe\FilamentVoight\Events\ProjectCreatedViaApi;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\FetchProjectFaviconJob;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

class LockFileSyncService
{
    /**
     * @param  array<string, UploadedFile>  $lockfiles
     * @param  string|null  $hosting  Comma-separated hosting names, replacing the environment's hostings; unknown ones are created. Null leaves them as they are.
     */
    public function sync(string $projectCode, string $environmentName, array $lockfiles, ?Project $project = null, ?string $url = null, ?string $hosting = null): DependencySync
    {
        $project = $project ?? $this->resolveProject($projectCode);
        $environment = $this->resolveEnvironment($project, $environmentName);

        if ($url !== null) {
            $this->updateEnvironmentUrl($project, $environment, $url);
        }

        if (filled($hosting)) {
            $environment->syncHostings(explode(',', $hosting));
        }

        $result = $this->storeLockFilesAndComputeHash($project, $environment, $lockfiles);

        $dependencySyncModel = FilamentVoight::config()->getDependencySyncModel();
        $sync = $dependencySyncModel::create([
            'environment_id' => $environment->id,
            'lockfile_hash' => $result['hash'],
            'lockfile_paths' => $result['paths'],
            'status' => DependencySyncStatus::Pending,
        ]);

        ProcessLockFilesJob::dispatch($sync);

        return $sync;
    }

    private function resolveProject(string $projectCode): Project
    {
        $projectModel = FilamentVoight::config()->getProjectModel();
        $project = $projectModel::firstOrCreate(
            ['project_code' => $projectCode],
        );

        if ($project->wasRecentlyCreated) {
            ProjectCreatedViaApi::dispatch($project);
        }

        return $project;
    }

    public function resolveEnvironment(Project $project, string $environmentName): Environment
    {
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();
        $environment = $environmentModel::firstOrCreate(
            ['project_id' => $project->id, 'name' => $environmentName],
        );

        if ($environment->wasRecentlyCreated) {
            EnvironmentCreatedViaApi::dispatch($environment);
        }

        return $environment;
    }

    private function updateEnvironmentUrl(Project $project, Environment $environment, string $url): void
    {
        $environment->update(['url' => rtrim($url, '/')]);

        if (! FilamentVoight::config()->isFaviconFetchingEnabled()) {
            return;
        }

        if ($environment->wasChanged('url') || app(ProjectFaviconService::class)->isDue($project)) {
            FetchProjectFaviconJob::dispatch($project);
        }
    }

    /**
     * @param  array<string, UploadedFile>  $lockfiles
     * @return array{paths: array<string>, hash: string}
     */
    private function storeLockFilesAndComputeHash(Project $project, Environment $environment, array $lockfiles): array
    {
        $disk = Storage::disk(FilamentVoight::config()->getLockfilesDisk());
        $basePath = "{$project->project_code}/{$environment->name}";
        $storedPaths = [];
        $contentParts = [];

        foreach ($lockfiles as $file) {
            $content = $file->getContent();
            $path = "{$basePath}/{$file->getClientOriginalName()}";
            $disk->put($path, $content);
            $storedPaths[] = $path;
            $contentParts[$file->getClientOriginalName()] = $content;
        }

        ksort($contentParts);

        return [
            'paths' => $storedPaths,
            'hash' => hash('sha256', implode('', $contentParts)),
        ];
    }
}
