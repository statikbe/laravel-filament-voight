<?php

use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;

beforeEach(function () {
    $this->actingAs(new \Illuminate\Foundation\Auth\User());
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
