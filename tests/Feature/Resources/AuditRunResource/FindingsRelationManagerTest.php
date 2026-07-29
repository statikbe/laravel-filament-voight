<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

beforeEach(function () {
    $this->actingAs(new User);
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

it('sorts findings worst-first by severity', function () {
    $run = AuditRun::factory()->create();

    // Created low-to-high so insertion order is the opposite of the expected order:
    // sorting by id would produce exactly the reverse of this assertion.
    $low = AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 2.0])->id,
    ]);
    $medium = AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 5.5])->id,
    ]);
    $critical = AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 9.8])->id,
    ]);

    Livewire::test(FindingsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewAuditRun::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$critical, $medium, $low], inOrder: true);
});
