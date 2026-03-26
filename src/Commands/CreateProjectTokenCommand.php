<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Models\Project;

class CreateProjectTokenCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:create-token
        {--project= : The project code}
        {--name= : Token name (e.g. "ci-pipeline")}';

    public $description = 'Generate an API token for a project';

    public function handle(): int
    {
        $this->displayBanner();

        $projectCode = $this->option('project') ?? $this->ask('Project code');

        $project = Project::where('project_code', $projectCode)->first();

        if (! $project) {
            $this->error("Project with code '{$projectCode}' not found.");

            return self::FAILURE;
        }

        $tokenName = $this->option('name') ?? $this->ask('Token name', 'ci-pipeline');

        $token = $project->createToken($tokenName);

        $this->info('Token created successfully!');
        $this->newLine();
        $this->line("  <fg=yellow>Project:</>  {$project->project_code}");
        $this->line("  <fg=yellow>Name:</>     {$tokenName}");
        $this->line("  <fg=yellow>Token:</>    <fg=green>{$token->plainTextToken}</>");
        $this->newLine();
        $this->warn('  Store this token securely — it will not be shown again.');
        $this->newLine();

        return self::SUCCESS;
    }
}
