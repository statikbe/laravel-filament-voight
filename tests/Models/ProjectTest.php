<?php

use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;

it('can be created via factory', function () {
    $project = Project::factory()->create();

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->project_code)->toBeString()
        ->and($project->is_muted)->toBeFalse();
});

it('belongs to a customer', function () {
    $project = Project::factory()->create();

    expect($project->customer)->toBeInstanceOf(Customer::class);
});

it('belongs to a team', function () {
    $project = Project::factory()->create();

    expect($project->team)->toBeInstanceOf(Team::class);
});

it('has many environments', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production']);

    expect($project->environments)->toHaveCount(1);
});

it('has many alert settings', function () {
    $project = Project::factory()->create();
    AlertSetting::factory()->for($project)->create();

    expect($project->alertSettings)->toHaveCount(1);
});

it('casts is_muted to boolean', function () {
    $project = Project::factory()->muted()->create();

    expect($project->is_muted)->toBeTrue()->toBeBool();
});
