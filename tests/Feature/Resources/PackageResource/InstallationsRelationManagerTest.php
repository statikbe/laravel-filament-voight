<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\InstallationsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lists environments where the package is installed', function () {
    $package = Package::factory()->create();
    $project = Project::factory()->create(['name' => 'Acme Widgets']);
    $env = Environment::factory()->for($project)->scanned()->create(['name' => 'production']);
    EnvironmentPackage::factory()
        ->for($env)
        ->for($package)
        ->create(['version' => '1.2.3', 'is_direct' => true, 'is_dev' => false]);

    Livewire::test(InstallationsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->assertCanSeeTableRecords($package->environmentPackages)
        ->assertSee('Acme Widgets')
        ->assertSee('production')
        ->assertSee('1.2.3');
});

it('renders an installation whose environment has never been scanned', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create(['name' => 'dev', 'scanned_at' => null]);
    $installation = EnvironmentPackage::factory()->for($env)->for($package)->create();

    Livewire::test(InstallationsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->assertCanSeeTableRecords([$installation]);
});

it('shows which installed packages require the installation', function () {
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);
    $environment = Environment::factory()->create();
    $installation = EnvironmentPackage::factory()->for($environment)->for($lodash)->transitive()->create();

    foreach (['express', 'webpack-dev-server'] as $parentName) {
        EnvironmentPackage::factory()
            ->for($environment)
            ->for(Package::factory()->npm()->create(['name' => $parentName]))
            ->create()
            ->children()
            ->attach($installation, ['constraint' => '^4.17.0', 'kind' => DependencyKind::Dependency]);
    }

    Livewire::test(InstallationsRelationManager::class, [
        'ownerRecord' => $lodash,
        'pageClass' => ViewPackage::class,
    ])
        ->assertSee('express')
        ->assertSee('webpack-dev-server');
});

it('filters installations by environment stage', function () {
    $package = Package::factory()->create();
    $production = EnvironmentPackage::factory()->for(Environment::factory()->create(['name' => 'production']))->for($package)->create();
    $staging = EnvironmentPackage::factory()->for(Environment::factory()->create(['name' => 'staging']))->for($package)->create();

    Livewire::test(InstallationsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('environment_stage', 'is', ['values' => ['production']]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$production])
        ->assertCanNotSeeTableRecords([$staging]);
});

it('filters installations by installed version range', function (array $settings, array $expected) {
    $package = Package::factory()->create();
    $installations = collect(['2.3.2', '3.0.0', 'v4.1.0', '5.3.1', '6.0.0'])->mapWithKeys(fn (string $version): array => [
        $version => EnvironmentPackage::factory()->for(Environment::factory()->create())->for($package)->create(['version' => $version]),
    ]);

    $rules = queryBuilderRule('installed_version', 'inRange', $settings + ['min_version' => null, 'max_version' => null]);

    Livewire::test(InstallationsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->set('tableDeferredFilters.queryBuilder.rules', $rules)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords($installations->only($expected))
        ->assertCanNotSeeTableRecords($installations->except($expected));
})->with([
    'min only' => [['min_operator' => '>', 'min_version' => '3.0.0'], ['v4.1.0', '5.3.1', '6.0.0']],
    'max only' => [['max_operator' => '<', 'max_version' => '3.0.0'], ['2.3.2']],
    'exclusive bounds' => [['min_operator' => '>', 'min_version' => '2.3.2', 'max_operator' => '<', 'max_version' => '5.3.1'], ['3.0.0', 'v4.1.0']],
    'inclusive bounds' => [['min_operator' => '>=', 'min_version' => '2.3.2', 'max_operator' => '<=', 'max_version' => '5.3.1'], ['2.3.2', '3.0.0', 'v4.1.0', '5.3.1']],
    'v prefix on bound' => [['min_operator' => '>=', 'min_version' => 'v4.1.0', 'max_operator' => '<', 'max_version' => 'v5.3.1'], ['v4.1.0']],
    'no match' => [['min_operator' => '>', 'min_version' => '9.0.0'], []],
]);
