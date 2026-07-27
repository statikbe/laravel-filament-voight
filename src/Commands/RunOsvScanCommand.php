<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\RunNightlyOsvScanJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;

class RunOsvScanCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:run-osv-scan
        {--nightly : Run the deduplicated nightly sweep across all scan_nightly environments}
        {--project= : Limit to a specific project code}
        {--environment= : Limit to a specific environment name}';

    public $description = 'Dispatch OSV vulnerability scan jobs for all (or a specific) environment';

    public function handle(): int
    {
        $this->displayBanner();

        if ($this->option('nightly')) {
            RunNightlyOsvScanJob::dispatch();
            $this->info('Dispatched nightly deduplicated OSV scan.');

            return self::SUCCESS;
        }

        if (! FilamentVoight::config()->getScannerUrl()) {
            $this->error('VOIGHT_SCANNER_URL is not configured. Set it in your .env file.');

            return self::FAILURE;
        }

        $environmentModel = FilamentVoight::config()->getEnvironmentModel();
        $query = $environmentModel::query()->with('project');

        if ($projectCode = $this->option('project')) {
            $projectModel = FilamentVoight::config()->getProjectModel();
            $project = $projectModel::where('project_code', $projectCode)->first();

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
            RunOsvScanJob::dispatch($environment, AuditRunTrigger::Manual);
            $this->line("  Queued scan for: {$environment->project->project_code} / {$environment->name}");
        }

        $this->info("Dispatched {$environments->count()} scan job(s).");

        return self::SUCCESS;
    }
}
