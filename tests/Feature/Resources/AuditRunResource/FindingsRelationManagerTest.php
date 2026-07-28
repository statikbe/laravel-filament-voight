<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

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

it('links each finding to its vulnerability page', function () {
    $run = AuditRun::factory()->create();
    $finding = AuditFinding::factory()->for($run, 'auditRun')->create();

    Livewire::test(FindingsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewAuditRun::class,
    ])
        ->assertTableActionHasUrl(
            'view',
            VulnerabilityResource::getUrl('view', ['record' => $finding->vulnerability_id]),
            $finding,
        );
});

it('shows the package type so php and npm findings are distinguishable', function () {
    $run = AuditRun::factory()->create();
    $finding = AuditFinding::factory()->for($run, 'auditRun')->create([
        'package_id' => Package::factory()->composer()->create()->id,
    ]);

    Livewire::test(FindingsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewAuditRun::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('package.type', PackageType::Composer, $finding);
});
