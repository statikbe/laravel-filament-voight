<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;

beforeEach(function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

it("shows the package's name in the view page", function () {
    $package = Package::factory()->create(['name' => 'test-vendor/unique-package-name']);
    $env = Environment::factory()->scanned()->create();
    EnvironmentPackage::factory()->for($env)->for($package)->create();

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('test-vendor/unique-package-name');
});

it('rejects a nonexistent package via HTTP', function () {
    // Newer Filament resolves the record before authorization and returns 404;
    // older 5.0.x versions authorize first and return 403. Either way the
    // request must be rejected — asserting a specific code tests Filament, not us.
    $status = $this->get(PackageResource::getUrl('view', ['record' => 'nonexistent-ulid']))->status();

    expect($status)->toBeIn([403, 404]);
});
