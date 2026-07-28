<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;

beforeEach(function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

it('lists only the findings belonging to this run', function () {
    $run = AuditRun::factory()->create();
    $mine = AuditFinding::factory()->for($run, 'auditRun')->create();
    $theirs = AuditFinding::factory()->for(AuditRun::factory()->create(), 'auditRun')->create();

    Livewire::test(FindingsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewAuditRun::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
