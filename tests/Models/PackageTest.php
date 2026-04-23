<?php

use Illuminate\Database\QueryException;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Package;

it('can be created via factory', function () {
    $package = Package::factory()->create();

    expect($package)->toBeInstanceOf(Package::class)
        ->and($package->name)->toBeString();
});

it('casts type to PackageType enum', function () {
    $package = Package::factory()->composer()->create();

    expect($package->type)->toBe(PackageType::Composer);
});

it('enforces unique constraint on name and type', function () {
    Package::factory()->create(['name' => 'laravel/framework', 'type' => PackageType::Composer]);

    Package::factory()->create(['name' => 'laravel/framework', 'type' => PackageType::Composer]);
})->throws(QueryException::class);

it('allows same name with different type', function () {
    Package::factory()->create(['name' => 'lodash', 'type' => PackageType::Composer]);
    $npmPackage = Package::factory()->create(['name' => 'lodash', 'type' => PackageType::Npm]);

    expect($npmPackage)->toBeInstanceOf(Package::class);
});

it('has many audit findings', function () {
    $package = Package::factory()->create();
    Statikbe\FilamentVoight\Models\AuditFinding::factory()->for($package)->create();

    expect($package->findings)->toHaveCount(1)
        ->and($package->findings->first())
        ->toBeInstanceOf(Statikbe\FilamentVoight\Models\AuditFinding::class);
});

it('has distinct projects through environment packages', function () {
    $package = Package::factory()->create();
    $project = Statikbe\FilamentVoight\Models\Project::factory()->create();
    $env1 = Statikbe\FilamentVoight\Models\Environment::factory()->for($project)->create(['name' => 'production']);
    $env2 = Statikbe\FilamentVoight\Models\Environment::factory()->for($project)->create(['name' => 'staging']);

    Statikbe\FilamentVoight\Models\EnvironmentPackage::factory()
        ->for($env1)->for($package)->create();
    Statikbe\FilamentVoight\Models\EnvironmentPackage::factory()
        ->for($env2)->for($package)->create();

    // Both environment packages point to the same project — projects() must de-duplicate.
    expect($package->projects()->count())->toBe(1)
        ->and($package->projects->first())
        ->toBeInstanceOf(Statikbe\FilamentVoight\Models\Project::class);
});
