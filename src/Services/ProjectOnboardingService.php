<?php

namespace Statikbe\FilamentVoight\Services;

use Laravel\Sanctum\NewAccessToken;
use RuntimeException;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;

/**
 * Creates projects and issues their sync tokens: the single place project tokens come from.
 */
class ProjectOnboardingService
{
    /**
     * @throws RuntimeException when the project code is taken; the existing project is left untouched
     */
    public function createProject(string $projectCode, string $name, ?string $repoUrl = null, ?Team $team = null, ?Customer $customer = null): Project
    {
        $projectModel = FilamentVoight::config()->getProjectModel();

        if ($projectModel::query()->where('project_code', $projectCode)->exists()) {
            throw new RuntimeException("A project with code `{$projectCode}` already exists.");
        }

        return $projectModel::query()->create([
            'project_code' => $projectCode,
            'name' => $name,
            'repo_url' => $repoUrl,
            'team_id' => $team?->getKey(),
            'customer_id' => $customer?->getKey(),
        ]);
    }

    /**
     * A token that lets CI push lockfiles for this project. Existing tokens are kept.
     */
    public function createToken(Project $project, ?string $name = null): NewAccessToken
    {
        return $project->createToken($name ?? $project::DEFAULT_API_TOKEN_NAME);
    }
}
