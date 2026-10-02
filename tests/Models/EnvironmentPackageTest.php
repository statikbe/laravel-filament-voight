<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
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

it('links installed packages to their dependencies and dependents with the edge details', function () {
    $environment = Environment::factory()->create();
    $express = EnvironmentPackage::factory()->for($environment)->create();
    $react = EnvironmentPackage::factory()->for($environment)->create();
    $lodash = EnvironmentPackage::factory()->for($environment)->transitive()->create();

    $express->children()->attach($lodash, ['constraint' => '^4.17.0', 'kind' => DependencyKind::Dependency]);
    $react->children()->attach($lodash, ['constraint' => '^4.0.0', 'kind' => DependencyKind::Peer]);

    expect($express->children()->sole()->is($lodash))->toBeTrue()
        ->and($express->children()->sole()->pivot->constraint)->toBe('^4.17.0')
        ->and($lodash->parents()->pluck('voight_environment_packages.id')->sort()->values()->all())
        ->toBe(collect([$express->id, $react->id])->sort()->values()->all())
        ->and($lodash->parents()->whereKey($react->id)->sole()->pivot->kind)->toBe(DependencyKind::Peer);
});
