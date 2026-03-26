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
