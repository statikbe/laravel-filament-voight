<?php

use Laravel\Sanctum\PersonalAccessToken;
use Statikbe\FilamentVoight\Models\Project;

it('issues a project token through the onboarding service', function () {
    $project = Project::factory()->create(['project_code' => 'APP']);

    $this->artisan('voight:create-token', ['--project' => 'APP', '--name' => 'ci'])
        ->expectsOutputToContain('Token created successfully!')
        ->assertSuccessful();

    expect($project->tokens()->sole()->name)->toBe('ci');
});

it('fails for an unknown project', function () {
    $this->artisan('voight:create-token', ['--project' => 'NOPE', '--name' => 'ci'])->assertFailed();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
