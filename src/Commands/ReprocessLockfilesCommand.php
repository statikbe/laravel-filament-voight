<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Models\Environment;

/**
 * Rebuild environments from the lockfiles already stored on the Voight disk, e.g.
 * after an upgrade that changes how lockfiles are parsed.
 */
class ReprocessLockfilesCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:reprocess-lockfiles
        {--project= : Limit to a specific project code}
        {--environment= : Limit to a specific environment name}
        {--fresh : First delete the installed packages and audit runs of the selected environments}';

    public $description = 'Re-run lockfile processing (and the post-sync scan) from each environment\'s latest completed sync';

    public function handle(): int
    {
        $this->displayBanner();

        $environments = $this->selectedEnvironments();

        if ($environments === null) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->wipe($environments);
        }

        $dispatched = 0;

        foreach ($environments as $environment) {
            $latestSync = $environment->dependencySyncs()
                ->where('status', DependencySyncStatus::Completed)
                ->latest()
                ->first();

            if ($latestSync === null) {
                $this->line("  Skipped (no completed sync): {$environment->project->project_code} / {$environment->name}");

                continue;
            }

            ProcessLockFilesJob::dispatch($latestSync);
            $this->line("  Queued: {$environment->project->project_code} / {$environment->name}");
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} lockfile processing job(s).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Environment>|null null when the given project does not exist
     */
    private function selectedEnvironments(): ?Collection
    {
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();
        $query = $environmentModel::query()->with('project');

        if ($projectCode = $this->option('project')) {
            $projectModel = FilamentVoight::config()->getProjectModel();
            $project = $projectModel::where('project_code', $projectCode)->first();

            if (! $project) {
                $this->error("Project '{$projectCode}' not found.");

                return null;
            }

            $query->where('project_id', $project->id);
        }

        if ($environmentName = $this->option('environment')) {
            $query->where('name', $environmentName);
        }

        return $query->get();
    }

    /**
     * Deleting installed packages cascades to their edges, deleting audit runs to
     * their findings. Packages, vulnerabilities and alert notification logs stay, so
     * alerts are only sent for findings that are genuinely new.
     *
     * @param  Collection<int, Environment>  $environments
     */
    private function wipe(Collection $environments): void
    {
        $environmentIds = $environments->pluck('id');

        DB::transaction(function () use ($environmentIds): void {
            FilamentVoight::config()->getEnvironmentPackageModel()::whereIn('environment_id', $environmentIds)->delete();
            FilamentVoight::config()->getAuditRunModel()::whereIn('environment_id', $environmentIds)->delete();
        });

        $this->warn("Deleted installed packages and audit runs of {$environmentIds->count()} environment(s).");
    }
}
