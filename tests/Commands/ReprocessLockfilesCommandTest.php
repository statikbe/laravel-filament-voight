<?php

use Illuminate\Support\Facades\Queue;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Project;

beforeEach(function () {
    Queue::fake();
});

it('reprocesses the latest completed sync of every environment', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create(['created_at' => now()->subDay()]);
    $latest = DependencySync::factory()->for($environment)->create();
    DependencySync::factory()->for($environment)->failed()->create(['created_at' => now()->addMinute()]);
    $neverSynced = Environment::factory()->create();

    $this->artisan('voight:reprocess-lockfiles')
        ->expectsOutputToContain($neverSynced->name)
        ->assertSuccessful();

    Queue::assertPushed(ProcessLockFilesJob::class, 1);
    Queue::assertPushed(ProcessLockFilesJob::class, fn (ProcessLockFilesJob $job): bool => $job->sync->is($latest));
});

it('limits the selection to a project and an environment', function () {
    $project = Project::factory()->create(['project_code' => 'acme']);
    $production = Environment::factory()->for($project)->create(['name' => 'production']);
    $staging = Environment::factory()->for($project)->create(['name' => 'staging']);
    $otherProject = Environment::factory()->create(['name' => 'production']);

    foreach ([$production, $staging, $otherProject] as $environment) {
        DependencySync::factory()->for($environment)->create();
    }

    $this->artisan('voight:reprocess-lockfiles --project=acme --environment=production')->assertSuccessful();

    Queue::assertPushed(ProcessLockFilesJob::class, 1);
    Queue::assertPushed(ProcessLockFilesJob::class, fn (ProcessLockFilesJob $job): bool => $job->sync->environment_id === $production->id);
});

it('fails for an unknown project', function () {
    $this->artisan('voight:reprocess-lockfiles --project=missing')->assertFailed();

    Queue::assertNothingPushed();
});

it('wipes installed packages and audit history of only the selected environments with --fresh', function () {
    $project = Project::factory()->create(['project_code' => 'acme']);
    $selected = Environment::factory()->for($project)->create(['name' => 'production']);
    $untouched = Environment::factory()->create();

    foreach ([$selected, $untouched] as $environment) {
        DependencySync::factory()->for($environment)->create();
        EnvironmentPackage::factory()->for($environment)->create();
        AuditFinding::factory()->for(AuditRun::factory()->for($environment))->create();
    }

    $this->artisan('voight:reprocess-lockfiles --project=acme --fresh')->assertSuccessful();

    expect(EnvironmentPackage::where('environment_id', $selected->id)->exists())->toBeFalse()
        ->and(AuditRun::where('environment_id', $selected->id)->exists())->toBeFalse()
        ->and(EnvironmentPackage::where('environment_id', $untouched->id)->exists())->toBeTrue()
        ->and(AuditRun::where('environment_id', $untouched->id)->exists())->toBeTrue()
        ->and(AuditFinding::count())->toBe(1)
        ->and(DependencySync::where('environment_id', $selected->id)->sole()->status)->toBe(DependencySyncStatus::Completed);
});
