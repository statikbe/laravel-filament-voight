<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\SyncWarning;
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

/**
 * @return array{healthy: Environment, failing: Environment, warning: Environment}
 */
function listEnvironmentsHealthFixture(): array
{
    $healthy = Environment::factory()->create(['name' => 'production']);
    DependencySync::factory()->for($healthy)->create();
    $failing = Environment::factory()->create(['name' => 'staging']);
    DependencySync::factory()->for($failing)->failed()->create();
    $warning = Environment::factory()->create(['name' => 'testing']);
    DependencySync::factory()->for($warning)->create(['warnings' => [['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']]]]);

    return compact('healthy', 'failing', 'warning');
}

it('shows every environment on the default all tab', function () {
    $environments = listEnvironmentsHealthFixture();

    Livewire::test(ListEnvironments::class)
        ->assertSet('activeTab', 'all')
        ->assertCanSeeTableRecords(array_values($environments));
});

it('scopes each tab', function (string $tab, array $visible, array $hidden) {
    $environments = listEnvironmentsHealthFixture();

    Livewire::test(ListEnvironments::class)
        ->set('activeTab', $tab)
        ->assertCanSeeTableRecords(array_map(fn (string $key) => $environments[$key], $visible))
        ->assertCanNotSeeTableRecords(array_map(fn (string $key) => $environments[$key], $hidden));
})->with([
    'failing' => ['failing', ['failing'], ['healthy', 'warning']],
    'warnings' => ['warnings', ['warning'], ['healthy', 'failing']],
    'production' => ['production', ['healthy'], ['failing', 'warning']],
]);

it('treats live as production on the production tab', function () {
    $live = Environment::factory()->create(['name' => 'live']);

    Livewire::test(ListEnvironments::class)
        ->set('activeTab', 'production')
        ->assertCanSeeTableRecords([$live]);
});

it('combines a tab with a query builder filter', function () {
    $project = Project::factory()->create();
    $match = Environment::factory()->for($project)->create();
    $otherFailing = Environment::factory()->create();

    Livewire::test(ListEnvironments::class)
        ->set('activeTab', 'failing')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('customer', 'is', ['values' => [$project->customer_id]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$otherFailing]);
});

it('badges the navigation with the failing environment count', function () {
    expect(ListEnvironments::getNavigationBadge())->toBeNull();

    Environment::factory()->count(2)->create();
    $healthy = Environment::factory()->create();
    DependencySync::factory()->for($healthy)->create();

    expect(ListEnvironments::getNavigationBadge())->toBe('2')
        ->and(ListEnvironments::getNavigationBadgeColor())->toBe('danger')
        ->and(ListEnvironments::getNavigationGroup())->toBe(voightTrans('navigation.monitoring'));
});

it('counts the records of each tab in its badge', function () {
    listEnvironmentsHealthFixture();

    $tabs = Livewire::test(ListEnvironments::class)->instance()->getCachedTabs();

    expect((int) $tabs['production']->getBadge())->toBe(1)
        ->and((int) $tabs['failing']->getBadge())->toBe(1)
        ->and((int) $tabs['warnings']->getBadge())->toBe(1)
        ->and((int) $tabs['all']->getBadge())->toBe(3);
});
