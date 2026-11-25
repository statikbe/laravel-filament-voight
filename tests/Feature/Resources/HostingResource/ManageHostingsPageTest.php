<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\HostingTag;
use Statikbe\FilamentVoight\Resources\HostingResource\Pages\ManageHostings;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lists hosting tags with their environment count', function () {
    Environment::factory()->count(2)->sequence(['name' => 'production'], ['name' => 'staging'])->create()
        ->each(fn (Environment $environment) => $environment->syncHostings(['Combell']));
    $combell = HostingTag::query()->sole();

    Livewire::test(ManageHostings::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$combell])
        ->assertTableColumnStateSet('environments_count', 2, $combell)
        ->searchTable('comb')
        ->assertCanSeeTableRecords([$combell]);
});

it('creates and renames a hosting tag', function () {
    Livewire::test(ManageHostings::class)
        ->callAction('create', data: ['name' => 'Level27'])
        ->assertHasNoActionErrors();

    $hosting = HostingTag::query()->sole();

    Livewire::test(ManageHostings::class)
        ->callTableAction('edit', $hosting, data: ['name' => 'Level 27'])
        ->assertHasNoTableActionErrors();

    expect($hosting->fresh()->name)->toBe('Level 27')
        ->and($hosting->fresh()->type)->toBe(HostingTag::TYPE);
});

it('rejects a name that differs only in case', function () {
    HostingTag::factory()->create(['name' => ['en' => 'Combell']]);

    Livewire::test(ManageHostings::class)
        ->callAction('create', data: ['name' => 'combell'])
        ->assertHasActionErrors(['name']);
});
