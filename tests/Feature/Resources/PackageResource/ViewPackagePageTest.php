<?php

use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;

beforeEach(function () {
    $this->actingAs(new \Illuminate\Foundation\Auth\User());
});

it('renders for an existing package', function () {
    $package = Package::factory()->create(['name' => 'laravel/framework']);
    $env = Environment::factory()->scanned()->create();
    EnvironmentPackage::factory()->for($env)->for($package)->create();

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('laravel/framework');
});

it('returns 404 for a nonexistent package', function () {
    expect(fn () => Livewire::test(ViewPackage::class, ['record' => 'nonexistent-ulid']))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
