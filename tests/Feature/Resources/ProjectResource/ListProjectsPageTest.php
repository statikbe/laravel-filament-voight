<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
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

    filterProjects('current_severity', 'is', ['values' => [Severity::Critical->value]])
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$fixed]);
});

it('lists every project on the default all tab', function () {
    $projects = Project::factory()->count(2)->create();

    Livewire::test(ListProjects::class)
        ->assertSet('activeTab', 'all')
        ->assertCanSeeTableRecords($projects);
});

it('shows only projects of the user teams on the my team tab', function () {
    $teamA = Team::factory()->create();
    $mine = Project::factory()->for($teamA)->create();
    $other = Project::factory()->for(Team::factory())->create();

    $this->actingAs(mcpUser($teamA));

    Livewire::test(ListProjects::class)
        ->set('activeTab', 'my_team')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows no projects on the my team tab for a user without a team', function () {
    $project = Project::factory()->for(Team::factory())->create();

    $this->actingAs(mcpUser());

    Livewire::test(ListProjects::class)
        ->set('activeTab', 'my_team')
        ->assertCanNotSeeTableRecords([$project]);
});

it('shows only projects with a current critical or high finding on production on its tab', function (string $environmentName, float $score, bool $visible) {
    $project = Project::factory()->create();
    $run = AuditRun::factory()->for(Environment::factory()->for($project)->state(['name' => $environmentName]), 'environment')->create();
    AuditFinding::factory()->for($run, 'auditRun')->for(Vulnerability::factory()->state(['vulnerability_score' => $score]))->create();
    $clean = Project::factory()->create();

    $component = Livewire::test(ListProjects::class)
        ->set('activeTab', 'critical_high_on_production')
        ->assertCanNotSeeTableRecords([$clean])
        ->assertSet('activeTab', 'critical_high_on_production');

    $visible
        ? $component->assertCanSeeTableRecords([$project])
        : $component->assertCanNotSeeTableRecords([$project]);
})->with([
    'critical on production' => ['production', 9.8, true],
    'high on production' => ['production', 7.5, true],
    'medium on production' => ['production', 5.0, false],
    'critical on staging' => ['staging', 9.8, false],
]);

it('hides a project whose critical finding is on a superseded production run', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->state(['name' => 'production'])->create();
    $old = AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()->subDay()]);
    AuditFinding::factory()->for($old, 'auditRun')->for(Vulnerability::factory()->critical())->create();
    AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()]);

    Livewire::test(ListProjects::class)
        ->set('activeTab', 'critical_high_on_production')
        ->assertCanNotSeeTableRecords([$project]);
});

it('counts the records of each tab in its badge', function () {
    $team = Team::factory()->create();
    Project::factory()->for($team)->create();
    $risky = Project::factory()->create();
    $run = AuditRun::factory()->for(Environment::factory()->for($risky)->state(['name' => 'production']), 'environment')->create();
    AuditFinding::factory()->for($run, 'auditRun')->for(Vulnerability::factory()->critical())->create();

    $this->actingAs(mcpUser($team));

    $tabs = Livewire::test(ListProjects::class)->instance()->getCachedTabs();

    expect((int) $tabs['all']->getBadge())->toBe(2)
        ->and((int) $tabs['my_team']->getBadge())->toBe(1)
        ->and((int) $tabs['critical_high_on_production']->getBadge())->toBe(1);
});
