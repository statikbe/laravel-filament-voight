<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;

beforeEach(function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

it('shows an audit run', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])
        ->assertSuccessful();
});

it('rejects a nonexistent audit run via HTTP', function () {
    // Filament returns 404 or 403 depending on version; either is a rejection.
    $status = $this->get(AuditRunResource::getUrl('view', ['record' => 'nonexistent-ulid']))->status();

    expect($status)->toBeIn([403, 404]);
});
