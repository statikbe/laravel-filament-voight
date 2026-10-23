<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lists packages', function () {
    $package = Package::factory()->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$package]);
});

it('filters packages by type through the query builder', function () {
    $composer = Package::factory()->composer()->create();
    $npm = Package::factory()->npm()->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('type', 'is', ['values' => [PackageType::Npm->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$npm])
        ->assertCanNotSeeTableRecords([$composer]);
});

it('filters packages by findings count through the query builder', function () {
    $busy = Package::factory()->create();
    AuditFinding::factory()->for($busy)->count(3)->create();
    $quiet = Package::factory()->create();
    AuditFinding::factory()->for($quiet)->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('findings', 'hasMin', ['count' => 2]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$busy])
        ->assertCanNotSeeTableRecords([$quiet]);
});

it('filters packages by installations count through the query builder', function () {
    $popular = Package::factory()->create();
    EnvironmentPackage::factory()->for($popular)->count(2)->create();
    $rare = Package::factory()->create();
    EnvironmentPackage::factory()->for($rare)->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('environmentPackages', 'hasMin', ['count' => 2]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$popular])
        ->assertCanNotSeeTableRecords([$rare]);
});

it('filters packages by project through the query builder', function () {
    $environment = Environment::factory()->create();
    $used = Package::factory()->create();
    EnvironmentPackage::factory()->for($environment)->for($used)->create();
    $unused = Package::factory()->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('project', 'is', ['values' => [$environment->project_id]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$used])
        ->assertCanNotSeeTableRecords([$unused]);
});

it('filters packages by the stage of the environments they are installed on', function (string $operator, bool $installedVisible) {
    $installed = Package::factory()->create();
    EnvironmentPackage::factory()->for(Environment::factory()->state(['name' => 'production']))->for($installed)->create();
    $other = Package::factory()->create();
    EnvironmentPackage::factory()->for(Environment::factory()->state(['name' => 'staging']))->for($other)->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('environment_stage', $operator, ['values' => [EnvironmentStage::Production->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords($installedVisible ? [$installed] : [$other])
        ->assertCanNotSeeTableRecords($installedVisible ? [$other] : [$installed]);
})->with([
    'is' => ['is', true],
    'is not' => ['is.inverse', false],
]);

it('shows only packages with a current finding on the vulnerable in use tab', function () {
    $vulnerable = Package::factory()->create();
    AuditFinding::factory()->for($vulnerable)->create();
    $clean = Package::factory()->create();

    Livewire::test(ListPackages::class)
        ->assertSet('activeTab', 'vulnerable_in_use')
        ->assertCanSeeTableRecords([$vulnerable])
        ->assertCanNotSeeTableRecords([$clean]);
});

it('lists every package on the all tab', function () {
    $vulnerable = Package::factory()->create();
    AuditFinding::factory()->for($vulnerable)->create();
    $clean = Package::factory()->create();

    Livewire::test(ListPackages::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$vulnerable, $clean]);
});

it('hides a package whose only finding is on a superseded run from the vulnerable in use tab', function () {
    $environment = Environment::factory()->create();
    $stale = Package::factory()->create();
    AuditFinding::factory()->for($stale)->for(AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()->subDay()]), 'auditRun')->create();
    AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()]);

    Livewire::test(ListPackages::class)
        ->assertCanNotSeeTableRecords([$stale]);
});

it('counts the records of each tab in its badge', function () {
    AuditFinding::factory()->for(Package::factory()->create())->create();
    Package::factory()->count(2)->create();

    $tabs = Livewire::test(ListPackages::class)->instance()->getCachedTabs();

    expect((int) $tabs['vulnerable_in_use']->getBadge())->toBe(1)
        ->and((int) $tabs['all']->getBadge())->toBe(3);
});
