<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

function healthyEnvironment(Project $project, string $name): Environment
{
    $environment = Environment::factory()->for($project)->create(['name' => $name]);
    DependencySync::factory()->for($environment)->create();
    AuditRun::factory()->for($environment)->create();

    return $environment;
}

it('shows a callout with the messages of every environment that has issues', function () {
    $project = Project::factory()->create();
    healthyEnvironment($project, 'staging');

    $production = Environment::factory()->for($project)->create(['name' => 'production']);
    DependencySync::factory()->for($production)->create(['warnings' => [
        ['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']],
    ]]);
    AuditRun::factory()->for($production)->failed()->create(['error_message' => 'Scanner returned 500']);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('production')
        ->assertSee('Scanner returned 500')
        ->assertSee(SyncWarning::UnsupportedLockfile->message(['file' => 'pnpm-lock.yaml']))
        ->assertDontSee(EnvironmentIssueType::NeverSynced->getLabel());
});

it('shows no callout for a healthy project', function () {
    $project = Project::factory()->create();
    healthyEnvironment($project, 'production');

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSeeHtml('fi-callout');
});

it("shows each environment's worst issue in the health column", function () {
    $project = Project::factory()->create();
    $healthy = healthyEnvironment($project, 'staging');
    $failing = Environment::factory()->for($project)->create(['name' => 'production']);
    DependencySync::factory()->for($failing)->failed()->create(['error_message' => 'Malformed composer.lock']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('health', EnvironmentIssueType::SyncFailed->value, $failing)
        ->assertTableColumnStateSet('health', 'healthy', $healthy)
        ->assertSee('Malformed composer.lock');
});
