<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;

beforeEach(function () {
    $this->actingAs(new User);
});

function filterProjects(string $type, string $operator, array $settings = []): Testable
{
    return Livewire::test(ListProjects::class)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule($type, $operator, $settings))
        ->call('applyTableFilters');
}

it('lists projects', function () {
    $project = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$project]);
});

it('filters projects by customer', function () {
    $match = Project::factory()->create();
    $other = Project::factory()->create();

    filterProjects('customer', 'is', ['values' => [$match->customer_id]])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters projects by team', function () {
    $match = Project::factory()->create();
    $other = Project::factory()->create();

    filterProjects('team', 'is', ['values' => [$match->team_id]])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters projects by muted state', function () {
    $muted = Project::factory()->muted()->create();
    $active = Project::factory()->create();

    filterProjects('is_muted', 'isTrue')
        ->assertCanSeeTableRecords([$muted])
        ->assertCanNotSeeTableRecords([$active]);
});

it('filters projects by name', function () {
    $match = Project::factory()->create(['name' => 'Alpha site']);
    $other = Project::factory()->create(['name' => 'Beta shop']);

    filterProjects('name', 'contains', ['text' => 'alpha'])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters projects by environment count', function () {
    $busy = Project::factory()->create();
    Environment::factory()->for($busy)->sequence(['name' => 'production'], ['name' => 'staging'])->count(2)->create();
    $quiet = Project::factory()->create();
    Environment::factory()->for($quiet)->create();

    filterProjects('environments', 'hasMin', ['count' => 2])
        ->assertCanSeeTableRecords([$busy])
        ->assertCanNotSeeTableRecords([$quiet]);
});

it('filters projects by the stage of their environments', function (string $operator, bool $productionVisible) {
    $production = Project::factory()->create();
    Environment::factory()->for($production)->create(['name' => 'production']);
    $staging = Project::factory()->create();
    Environment::factory()->for($staging)->create(['name' => 'staging']);

    filterProjects('environment_stage', $operator, ['values' => [EnvironmentStage::Production->value]])
        ->assertCanSeeTableRecords($productionVisible ? [$production] : [$staging])
        ->assertCanNotSeeTableRecords($productionVisible ? [$staging] : [$production]);
})->with([
    'is' => ['is', true],
    'is not' => ['is.inverse', false],
]);

it('filters projects by the severity of their current findings only', function () {
    $critical = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);

    $current = Project::factory()->create();
    $currentEnvironment = Environment::factory()->for($current)->create();
    AuditFinding::factory()->for(AuditRun::factory()->for($currentEnvironment)->create(['started_at' => now()]), 'auditRun')
        ->for($critical)->create();

    $fixed = Project::factory()->create();
    $fixedEnvironment = Environment::factory()->for($fixed)->create();
    AuditFinding::factory()->for(AuditRun::factory()->for($fixedEnvironment)->create(['started_at' => now()->subDay()]), 'auditRun')
        ->for($critical)->create();
    AuditRun::factory()->for($fixedEnvironment)->create(['started_at' => now()]);

    filterProjects('current_severity', 'is', ['values' => ['critical']])
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$fixed]);
});
