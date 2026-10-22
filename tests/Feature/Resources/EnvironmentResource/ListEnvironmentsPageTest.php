<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;

beforeEach(function () {
    $this->actingAs(new User);
});

function filterEnvironments(string $type, string $operator, array $settings): Testable
{
    return Livewire::test(ListEnvironments::class)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule($type, $operator, $settings))
        ->call('applyTableFilters');
}

it('lists environments', function () {
    $environment = Environment::factory()->create();

    Livewire::test(ListEnvironments::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$environment]);
});

it('filters environments by stage', function (string $operator, bool $productionVisible) {
    $production = Environment::factory()->create(['name' => 'production']);
    $staging = Environment::factory()->create(['name' => 'staging']);

    filterEnvironments('environment_stage', $operator, ['values' => [EnvironmentStage::Production->value]])
        ->assertCanSeeTableRecords($productionVisible ? [$production] : [$staging])
        ->assertCanNotSeeTableRecords($productionVisible ? [$staging] : [$production]);
})->with([
    'is' => ['is', true],
    'is not' => ['is.inverse', false],
]);

it('filters environments by project customer', function () {
    $project = Project::factory()->create();
    $match = Environment::factory()->for($project)->create();
    $other = Environment::factory()->create();

    filterEnvironments('customer', 'is', ['values' => [$project->customer_id]])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters environments by project team', function () {
    $project = Project::factory()->create();
    $match = Environment::factory()->for($project)->create();
    $other = Environment::factory()->create();

    filterEnvironments('team', 'is', ['values' => [$project->team_id]])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters environments by nightly scan', function () {
    $nightly = Environment::factory()->create();
    $manual = Environment::factory()->notNightly()->create();

    filterEnvironments('scan_nightly', 'isTrue', [])
        ->assertCanSeeTableRecords([$nightly])
        ->assertCanNotSeeTableRecords([$manual]);
});

it('filters environments by latest finished audit status', function () {
    $failed = Environment::factory()->create();
    AuditRun::factory()->for($failed)->failed()->create();
    $completed = Environment::factory()->create();
    AuditRun::factory()->for($completed)->create();

    filterEnvironments('latest_audit_status', 'is', ['values' => [AuditRunStatus::Failed->value]])
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('filters environments by latest finished sync status', function () {
    $failed = Environment::factory()->create();
    DependencySync::factory()->for($failed)->create(['status' => DependencySyncStatus::Failed]);
    $completed = Environment::factory()->create();
    DependencySync::factory()->for($completed)->create(['status' => DependencySyncStatus::Completed]);

    filterEnvironments('latest_sync_status', 'is', ['values' => [DependencySyncStatus::Failed->value]])
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$completed]);
});
