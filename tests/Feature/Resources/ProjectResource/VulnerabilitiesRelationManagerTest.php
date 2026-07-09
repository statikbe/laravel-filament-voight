<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('narrows findings to composer packages when package_type filter is composer', function () {
    $project = Project::factory()->create();
    $env = Environment::factory()->for($project)->create();
    $run = AuditRun::factory()->for($env)->create(['started_at' => now()]);
    $vuln = Vulnerability::factory()->create();

    $composerPackage = Package::factory()->create(['type' => PackageType::Composer]);
    $npmPackage = Package::factory()->create(['type' => PackageType::Npm]);

    $composerFinding = AuditFinding::factory()
        ->for($run, 'auditRun')->for($composerPackage)->for($vuln)->create();
    $npmFinding = AuditFinding::factory()
        ->for($run, 'auditRun')->for($npmPackage)->for($vuln)->create();

    Livewire::test(VulnerabilitiesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('package_type', PackageType::Composer->value)
        ->assertCanSeeTableRecords([$composerFinding])
        ->assertCanNotSeeTableRecords([$npmFinding]);
});

it('shows all package types when package_type filter is blank', function () {
    $project = Project::factory()->create();
    $env = Environment::factory()->for($project)->create();
    $run = AuditRun::factory()->for($env)->create(['started_at' => now()]);
    $vuln = Vulnerability::factory()->create();

    $composerPackage = Package::factory()->create(['type' => PackageType::Composer]);
    $npmPackage = Package::factory()->create(['type' => PackageType::Npm]);

    $composerFinding = AuditFinding::factory()
        ->for($run, 'auditRun')->for($composerPackage)->for($vuln)->create();
    $npmFinding = AuditFinding::factory()
        ->for($run, 'auditRun')->for($npmPackage)->for($vuln)->create();

    Livewire::test(VulnerabilitiesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertCanSeeTableRecords([$composerFinding, $npmFinding]);
});
