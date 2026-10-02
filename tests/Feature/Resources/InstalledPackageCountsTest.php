<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('counts distinct packages per environment, not installed copies', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $debug = Package::factory()->npm()->create(['name' => 'debug']);
    EnvironmentPackage::factory()->for($environment)->for($debug)->create(['version' => '4.3.4']);
    EnvironmentPackage::factory()->for($environment)->for($debug)->create(['version' => '2.6.9']);
    EnvironmentPackage::factory()->for($environment)->create();

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertTableColumnStateSet('environment_packages_count', 2, $environment);
});

it('counts distinct environments per package, not installed copies', function () {
    $debug = Package::factory()->npm()->create(['name' => 'debug']);
    $production = Environment::factory()->create();
    EnvironmentPackage::factory()->for($production)->for($debug)->create(['version' => '4.3.4']);
    EnvironmentPackage::factory()->for($production)->for($debug)->create(['version' => '2.6.9']);
    EnvironmentPackage::factory()->for(Environment::factory())->for($debug)->create(['version' => '4.3.4']);

    Livewire::test(ListPackages::class)->assertTableColumnStateSet('environment_packages_count', 2, $debug);
});
