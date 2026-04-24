<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
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
