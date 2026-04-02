<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

class RunOsvScanCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:run-osv-scan
        {--project= : Limit to a specific project code}
        {--environment= : Limit to a specific environment name}';

    public $description = 'Dispatch OSV vulnerability scan jobs for all (or a specific) environment';

    public function handle(): int
    {
        $this->displayBanner();

        if (! FilamentVoight::config()->getScannerUrl()) {
            $this->error('VOIGHT_SCANNER_URL is not configured. Set it in your .env file.');

            return self::FAILURE;
        }

        $query = Environment::query()->with('project');

        if ($projectCode = $this->option('project')) {
            $project = Project::where('project_code', $projectCode)->first();

            if (! $project) {
                $this->error("Project '{$projectCode}' not found.");

                return self::FAILURE;
            }

            $query->where('project_id', $project->id);
        }

        if ($environmentName = $this->option('environment')) {
            $query->where('name', $environmentName);
        }

        $environments = $query->get();

        if ($environments->isEmpty()) {
            $this->warn('No environments found to scan.');

            return self::SUCCESS;
        }

        foreach ($environments as $environment) {
            RunOsvScanJob::dispatch($environment);
            $this->line("  Queued scan for: {$environment->project->project_code} / {$environment->name}");
        }

        $this->info("Dispatched {$environments->count()} scan job(s).");

        return self::SUCCESS;
    }
}
