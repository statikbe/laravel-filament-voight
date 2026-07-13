<?php

use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;

it('collapses the same package@version across environments but keeps differing versions', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $envA = Environment::factory()->create();
    $envB = Environment::factory()->create();

    EnvironmentPackage::factory()->create(['environment_id' => $envA->id, 'package_id' => $laravel->id, 'version' => 'v10.9.0']);
    EnvironmentPackage::factory()->create(['environment_id' => $envB->id, 'package_id' => $laravel->id, 'version' => 'v10.9.0']);
    EnvironmentPackage::factory()->create(['environment_id' => $envB->id, 'package_id' => $laravel->id, 'version' => 'v10.48.0']);

    $set = EnvironmentPackage::distinctPackageSetForEnvironments(collect([$envA, $envB]));

    expect($set)->toHaveCount(2);
    $versions = $set->pluck('version')->sort()->values()->all();
    expect($versions)->toBe(['v10.48.0', 'v10.9.0'])
        ->and($set->first()['type'])->toBe(PackageType::Composer);
});

it('excludes environments not passed in', function () {
    $pkg = Package::factory()->npm()->create();
    $included = Environment::factory()->create();
    $excluded = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $included->id, 'package_id' => $pkg->id, 'version' => '1.0.0']);
    EnvironmentPackage::factory()->create(['environment_id' => $excluded->id, 'package_id' => $pkg->id, 'version' => '2.0.0']);

    $set = EnvironmentPackage::distinctPackageSetForEnvironments(collect([$included]));

    expect($set)->toHaveCount(1)
        ->and($set->first()['version'])->toBe('1.0.0');
});

it('returns an empty collection for no environments', function () {
    expect(EnvironmentPackage::distinctPackageSetForEnvironments(collect()))->toHaveCount(0);
});
