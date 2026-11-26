<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\Framework;
use Statikbe\FilamentVoight\Enums\UpgradeStatus;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Services\ProjectStackService;

beforeEach(function () {
    $this->actingAs(new User);
});

function stackProject(string $environment = 'production', ?string $laravel = null, ?string $php = null, ?string $filament = null): Project
{
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => $environment]);

    foreach ([Framework::Laravel->value => $laravel, Framework::Filament->value => $filament] as $name => $version) {
        if ($version !== null) {
            EnvironmentPackage::factory()->for($environment)->for(
                Package::query()->firstOrCreate(['name' => $name, 'type' => 'composer']),
            )->create(['version' => $version]);
        }
    }

    if ($php !== null) {
        EnvironmentSystemDetail::factory()->for($environment)->create(['php_version' => $php]);
    }

    return $project;
}

function filterStack(string $type, array $values, string $operator = 'is'): Testable
{
    return Livewire::test(ListProjects::class)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule($type, $operator, ['values' => $values]))
        ->call('applyTableFilters');
}

it('renders the stack columns with the installed versions', function () {
    $project = stackProject(laravel: 'v12.4.1', php: '8.4.1', filament: '5.0.0');

    Livewire::test(ListProjects::class)
        ->assertTableColumnStateSet('laravel_version', 'v12.4.1', $project)
        ->assertTableColumnStateSet('filament_version', '5.0.0', $project)
        ->assertTableColumnStateSet('php_version', '8.4.1', $project)
        ->assertTableColumnStateSet('upgrade_status', UpgradeStatus::NeedsUpgrade, $project);
});

it('hides the framework version columns by default and shows php and upgrade status', function () {
    $table = Livewire::test(ListProjects::class)->instance()->getTable();

    foreach (['laravel_version', 'filament_version'] as $name) {
        expect($table->getColumn($name)->isToggledHiddenByDefault())->toBeTrue();
    }

    foreach (['php_version', 'upgrade_status'] as $name) {
        expect($table->getColumn($name)->isToggledHiddenByDefault())->toBeFalse();
    }
});

it('lists the packages behind their latest major in the upgrade description', function () {
    $behind = stackProject(laravel: '11.3.0', filament: '5.1.0');
    $current = stackProject(laravel: '13.0.1');
    $unknown = Project::factory()->create();

    expect(ProjectStackService::upgradeDescription(ProjectStackService::withStack(Project::query())->find($behind->id)))->toBe('Laravel 11.3.0 → 13')
        ->and(ProjectStackService::upgradeDescription(ProjectStackService::withStack(Project::query())->find($current->id)))->toBeNull()
        ->and(ProjectStackService::upgradeDescription(ProjectStackService::withStack(Project::query())->find($unknown->id)))->toBeNull();

    $both = stackProject(laravel: 'v12.4.1', filament: '4.2.0');

    expect(ProjectStackService::upgradeDescription(ProjectStackService::withStack(Project::query())->find($both->id)))->toBe("Laravel v12.4.1 → 13\nFilament 4.2.0 → 5");
});

it('renders a project without stack data', function () {
    $empty = Project::factory()->create();
    $withoutSync = stackProject();

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$empty, $withoutSync])
        ->assertTableColumnStateSet('laravel_version', null, $empty)
        ->assertTableColumnStateSet('php_version', null, $withoutSync)
        ->assertTableColumnStateSet('upgrade_status', UpgradeStatus::Unknown, $empty);
});

it('reads the stack from the highest stage environment', function () {
    $project = stackProject('staging', laravel: '13.0.0', php: '8.4.1');
    $production = Environment::factory()->for($project)->create(['name' => 'production']);
    EnvironmentPackage::factory()->for($production)->for(Package::query()->where('name', Framework::Laravel->value)->first())->create(['version' => '11.9.0']);

    Livewire::test(ListProjects::class)
        ->assertTableColumnStateSet('laravel_version', '11.9.0', $project)
        ->assertTableColumnStateSet('php_version', null, $project);
});

it('derives the upgrade status from the latest majors', function (string $laravel, UpgradeStatus $status) {
    $project = stackProject(laravel: $laravel);

    expect(ProjectStackService::upgradeStatus(ProjectStackService::withStack(Project::query())->find($project->id)))->toBe($status);
})->with([
    'behind' => ['12.0.0', UpgradeStatus::NeedsUpgrade],
    'latest' => ['13.1.0', UpgradeStatus::Current],
    'dev branch' => ['dev-main', UpgradeStatus::Current],
]);

it('sorts projects by upgrade status then name', function (string $direction, array $expected) {
    $unknown = Project::factory()->create(['name' => 'A unknown']);
    $current = stackProject(laravel: '13.0.0');
    $current->update(['name' => 'B current']);
    $outdatedZ = stackProject(laravel: '12.0.0');
    $outdatedZ->update(['name' => 'Z outdated']);
    $outdatedM = stackProject(filament: '4.0.0');
    $outdatedM->update(['name' => 'M outdated']);

    $projects = ['unknown' => $unknown, 'current' => $current, 'outdatedZ' => $outdatedZ, 'outdatedM' => $outdatedM];

    Livewire::test(ListProjects::class)
        ->sortTable('upgrade_status', $direction)
        ->assertCanSeeTableRecords(array_map(fn (string $key): Project => $projects[$key], $expected), inOrder: true);
})->with([
    'ascending' => ['asc', ['outdatedM', 'outdatedZ', 'current', 'unknown']],
    'descending' => ['desc', ['unknown', 'current', 'outdatedM', 'outdatedZ']],
]);

it('filters projects by upgrade status', function (UpgradeStatus $status, string $expected) {
    $projects = [
        'outdated' => stackProject(laravel: '12.0.0'),
        'current' => stackProject(laravel: '13.0.0'),
        'unknown' => Project::factory()->create(),
    ];

    filterStack('upgrade_status', [$status->value])
        ->assertCanSeeTableRecords([$projects[$expected]])
        ->assertCanNotSeeTableRecords(array_values(array_diff_key($projects, [$expected => 1])));
})->with([
    'needs upgrade' => [UpgradeStatus::NeedsUpgrade, 'outdated'],
    'current' => [UpgradeStatus::Current, 'current'],
    'unknown' => [UpgradeStatus::Unknown, 'unknown'],
]);

it('filters projects by php version', function () {
    $match = stackProject(php: '8.3.12');
    $other = stackProject(php: '8.4.1');

    filterStack('php_version', ['8.3'])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('sorts projects by version', function (string $direction) {
    $old = stackProject(laravel: '9.5.0', php: '8.1.2');
    $new = stackProject(laravel: '13.0.0', php: '8.4.1');
    $order = $direction === 'asc' ? [$old, $new] : [$new, $old];

    Livewire::test(ListProjects::class)
        ->sortTable('laravel_version', $direction)
        ->assertCanSeeTableRecords($order, inOrder: true)
        ->sortTable('php_version', $direction)
        ->assertCanSeeTableRecords($order, inOrder: true);
})->with(['asc', 'desc']);
