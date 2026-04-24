<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('defaults to latest audit run per environment', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->create();

    $oldRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subDays(2)]);
    $newRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subHour()]);

    $oldFinding = AuditFinding::factory()
        ->for($oldRun, 'auditRun')->for($package)->for($vuln)
        ->create(['installed_version' => '1.0.0']);
    $newFinding = AuditFinding::factory()
        ->for($newRun, 'auditRun')->for($package)->for($vuln)
        ->create(['installed_version' => '1.0.5']);

    Livewire::test(ActiveFindingsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->assertCanSeeTableRecords([$newFinding])
        ->assertCanNotSeeTableRecords([$oldFinding]);
});

it('shows all findings when latest_only filter is off', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->create();

    $oldRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subDays(2)]);
    $newRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subHour()]);

    $oldFinding = AuditFinding::factory()->for($oldRun, 'auditRun')->for($package)->for($vuln)->create();
    $newFinding = AuditFinding::factory()->for($newRun, 'auditRun')->for($package)->for($vuln)->create();

    Livewire::test(ActiveFindingsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->filterTable('latest_only', false)
        ->assertCanSeeTableRecords([$oldFinding, $newFinding]);
});

it('narrows findings by observed_at date range', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->create();

    $runInRange = AuditRun::factory()->for($env)->create(['started_at' => now()->subDay()]);
    $runOutOfRange = AuditRun::factory()->for($env)->create(['started_at' => now()->subMonth()]);

    $findingInRange = AuditFinding::factory()->for($runInRange, 'auditRun')->for($package)->for($vuln)->create();
    $findingOutOfRange = AuditFinding::factory()->for($runOutOfRange, 'auditRun')->for($package)->for($vuln)->create();

    Livewire::test(ActiveFindingsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->filterTable('latest_only', false)
        ->filterTable('observed_at', [
            'from' => now()->subDays(2)->toDateString(),
            'until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$findingInRange])
        ->assertCanNotSeeTableRecords([$findingOutOfRange]);
});

it('hides a finding that was patched between audit runs', function () {
    // Spec-explicit edge case: vuln A was hit in the old run; the new run only
    // has vuln B. The old-run finding (patched) must be hidden under default
    // latest_only=true scope.
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vulnPatched = Vulnerability::factory()->create(['source_id' => 'GHSA-patched']);
    $vulnStillHit = Vulnerability::factory()->create(['source_id' => 'GHSA-still-hit']);

    $oldRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subDays(2)]);
    $newRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subHour()]);

    $patchedFinding = AuditFinding::factory()
        ->for($oldRun, 'auditRun')->for($package)->for($vulnPatched)->create();
    $stillHitFinding = AuditFinding::factory()
        ->for($newRun, 'auditRun')->for($package)->for($vulnStillHit)->create();

    Livewire::test(ActiveFindingsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->assertCanSeeTableRecords([$stillHitFinding])
        ->assertCanNotSeeTableRecords([$patchedFinding]);
});

it('defaults to descending CVSS sort', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $run = AuditRun::factory()->for($env)->create(['started_at' => now()]);

    $low = Vulnerability::factory()->create(['vulnerability_score' => 3.0]);
    $critical = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);

    $lowFinding = AuditFinding::factory()->for($run, 'auditRun')->for($package)->for($low)->create();
    $criticalFinding = AuditFinding::factory()->for($run, 'auditRun')->for($package)->for($critical)->create();

    Livewire::test(ActiveFindingsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->assertCanSeeTableRecords([$criticalFinding, $lowFinding], inOrder: true);
});

it('groups findings by vulnerability source_id when grouping is applied', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $run = AuditRun::factory()->for($env)->create(['started_at' => now()]);

    $vulnA = Vulnerability::factory()->create(['source_id' => 'GHSA-vuln-A']);
    $vulnB = Vulnerability::factory()->create(['source_id' => 'GHSA-vuln-B']);

    $findingA = AuditFinding::factory()->for($run, 'auditRun')->for($package)->for($vulnA)->create();
    $findingB = AuditFinding::factory()->for($run, 'auditRun')->for($package)->for($vulnB)->create();

    Livewire::test(ActiveFindingsRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->set('tableGrouping', 'vulnerability.source_id')
        ->assertCanSeeTableRecords([$findingA, $findingB])
        ->assertSee('GHSA-vuln-A')
        ->assertSee('GHSA-vuln-B');
});
