<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

beforeEach(function () {
    $this->actingAs(new User);
});

it('narrows findings to composer packages when the package type rule is composer', function () {
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
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('package_type', 'is', ['values' => [PackageType::Composer->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$composerFinding])
        ->assertCanNotSeeTableRecords([$npmFinding]);
});

it('shows all package types when no filter rule is set', function () {
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

it('links each finding to its vulnerability page', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $run = AuditRun::factory()->for($environment)->create();
    $finding = AuditFinding::factory()->for($run, 'auditRun')->create();

    Livewire::test(VulnerabilitiesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionHasUrl(
            'view',
            VulnerabilityResource::getUrl('view', ['record' => $finding->vulnerability_id]),
            $finding,
        );
});

it('filters findings by severity through the query builder', function () {
    $project = Project::factory()->create();
    $run = AuditRun::factory()->for(Environment::factory()->for($project))->create(['started_at' => now()]);

    $critical = AuditFinding::factory()->for($run, 'auditRun')
        ->for(Vulnerability::factory()->create(['vulnerability_score' => 9.8]))->create();
    $low = AuditFinding::factory()->for($run, 'auditRun')
        ->for(Vulnerability::factory()->create(['vulnerability_score' => 2.0]))->create();

    Livewire::test(VulnerabilitiesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('severity', 'is', ['values' => [Severity::Critical->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$low])
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('severity', 'is.inverse', ['values' => [Severity::Critical->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$critical]);
});

it('combines severity and package type rules', function () {
    $project = Project::factory()->create();
    $run = AuditRun::factory()->for(Environment::factory()->for($project))->create(['started_at' => now()]);
    $composer = Package::factory()->create(['type' => PackageType::Composer]);
    $npm = Package::factory()->create(['type' => PackageType::Npm]);
    $critical = Vulnerability::factory()->create(['vulnerability_score' => 9.8]);
    $low = Vulnerability::factory()->create(['vulnerability_score' => 2.0]);

    $match = AuditFinding::factory()->for($run, 'auditRun')->for($composer)->for($critical)->create();
    $wrongType = AuditFinding::factory()->for($run, 'auditRun')->for($npm)->for($critical)->create();
    $wrongSeverity = AuditFinding::factory()->for($run, 'auditRun')->for($composer)->for($low)->create();

    Livewire::test(VulnerabilitiesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('severity', 'is', ['values' => [Severity::Critical->value]])
            + queryBuilderRule('package_type', 'is', ['values' => [PackageType::Composer->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$wrongType, $wrongSeverity]);
});

it('filters findings by environment stage and fix availability', function () {
    $project = Project::factory()->create();
    $production = AuditRun::factory()->for(Environment::factory()->for($project)->state(['name' => 'production']))->create(['started_at' => now()]);
    $staging = AuditRun::factory()->for(Environment::factory()->for($project)->state(['name' => 'staging']))->create(['started_at' => now()]);

    $productionFinding = AuditFinding::factory()->for($production, 'auditRun')->create(['fixed_version' => '2.0.0']);
    $stagingFinding = AuditFinding::factory()->for($staging, 'auditRun')->create(['fixed_version' => null]);

    $component = Livewire::test(VulnerabilitiesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('environment_stage', 'is', ['values' => [EnvironmentStage::Production->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$productionFinding])
        ->assertCanNotSeeTableRecords([$stagingFinding]);

    $component
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('fixed_version', 'isFilled', ['isFilled' => true]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$productionFinding])
        ->assertCanNotSeeTableRecords([$stagingFinding]);
});

it('applies the latest-only toggle by default next to the query builder', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();
    $oldRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDays(2)]);
    $newRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subHour()]);

    $old = AuditFinding::factory()->for($oldRun, 'auditRun')->create();
    $current = AuditFinding::factory()->for($newRun, 'auditRun')->create();

    Livewire::test(VulnerabilitiesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$old])
        ->set('tableDeferredFilters.latest_only.value', false)
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$current, $old]);
});
