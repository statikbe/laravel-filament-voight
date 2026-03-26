<?php

use Illuminate\Database\QueryException;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Project;

it('can be created via factory', function () {
    $environment = Environment::factory()->create();

    expect($environment)->toBeInstanceOf(Environment::class)
        ->and($environment->name)->toBeString();
});

it('belongs to a project', function () {
    $environment = Environment::factory()->create();

    expect($environment->project)->toBeInstanceOf(Project::class);
});

it('enforces unique constraint on project_id and name', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production']);

    Environment::factory()->for($project)->create(['name' => 'production']);
})->throws(QueryException::class);

it('has many environment packages', function () {
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->for($environment)->create();

    expect($environment->environmentPackages)->toHaveCount(1);
});

it('has many dependency syncs', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create();

    expect($environment->dependencySyncs)->toHaveCount(1);
});

it('has many audit runs', function () {
    $environment = Environment::factory()->create();
    AuditRun::factory()->for($environment)->create();

    expect($environment->auditRuns)->toHaveCount(1);
});

it('casts scanned_at to datetime', function () {
    $environment = Environment::factory()->scanned()->create();

    expect($environment->scanned_at)->toBeInstanceOf(Carbon\Carbon::class);
});
