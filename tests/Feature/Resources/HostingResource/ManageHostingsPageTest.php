<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Hosting;
use Statikbe\FilamentVoight\Resources\HostingResource\Pages\ManageHostings;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lists hostings with their environment count', function () {
    $combell = Hosting::factory()->create(['name' => 'Combell']);
    Environment::factory()->for($combell)->count(2)->sequence(['name' => 'production'], ['name' => 'staging'])->create();

    Livewire::test(ManageHostings::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$combell])
        ->assertTableColumnStateSet('environments_count', 2, $combell);
});

it('creates and renames a hosting', function () {
    Livewire::test(ManageHostings::class)
        ->callAction('create', data: ['name' => 'Level27'])
        ->assertHasNoActionErrors();

    $hosting = Hosting::query()->sole();

    Livewire::test(ManageHostings::class)
        ->callTableAction('edit', $hosting, data: ['name' => 'Level 27'])
        ->assertHasNoTableActionErrors();

    expect($hosting->fresh()->name)->toBe('Level 27');
});

it('rejects a duplicate name', function () {
    Hosting::factory()->create(['name' => 'Combell']);

    Livewire::test(ManageHostings::class)
        ->callAction('create', data: ['name' => 'Combell'])
        ->assertHasActionErrors(['name' => 'unique']);
});
