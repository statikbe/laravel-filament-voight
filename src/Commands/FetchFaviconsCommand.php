<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Services\ProjectFaviconService;

class FetchFaviconsCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:fetch-favicons
        {--project= : Limit to a specific project code}
        {--force : Refetch even when the favicon is still fresh}';

    public $description = 'Fetch project favicons from their environment URLs';

    public function handle(ProjectFaviconService $service): int
    {
        $this->displayBanner();

        if (! FilamentVoight::config()->isFaviconFetchingEnabled()) {
            $this->warn('Favicon fetching is disabled (filament-voight.favicons.enabled).');

            return self::SUCCESS;
        }

        $projectModel = FilamentVoight::config()->getProjectModel();
        $query = $projectModel::query()->whereHas('environments', fn ($query) => $query->whereNotNull('url'));

        if ($projectCode = $this->option('project')) {
            $query->where('project_code', $projectCode);
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->warn('No projects with an environment URL found.');

            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            if (! $this->option('force') && ! $service->isDue($project)) {
                $this->line("  Skipped (fresh): {$project->project_code}");

                continue;
            }

            $found = $service->fetch($project);
            $this->line(($found ? '  Fetched: ' : '  No favicon found: ') . $project->project_code);
        }

        return self::SUCCESS;
    }
}
