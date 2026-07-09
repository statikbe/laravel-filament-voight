<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Widgets\ActiveFindingsWidget;

beforeEach(function () {
    $this->actingAs(new User);

    $environment = Environment::factory()->create();
    $latestRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subHour()]);

    $this->composerFinding = AuditFinding::factory()
        ->for($latestRun, 'auditRun')
        ->for(Package::factory()->composer(), 'package')
        ->create();

    $this->npmFinding = AuditFinding::factory()
        ->for($latestRun, 'auditRun')
        ->for(Package::factory()->npm(), 'package')
        ->create();
});

it('shows every active finding when unfiltered', function () {
    Livewire::test(ActiveFindingsWidget::class)
        ->assertCanSeeTableRecords([$this->composerFinding, $this->npmFinding]);
});

it('shows only npm findings when filtered by npm package type', function () {
    Livewire::test(ActiveFindingsWidget::class)
        ->filterTable('package_type', 'npm')
        ->assertCanSeeTableRecords([$this->npmFinding])
        ->assertCanNotSeeTableRecords([$this->composerFinding]);
});

it('shows only composer findings when filtered by composer package type', function () {
    Livewire::test(ActiveFindingsWidget::class)
        ->filterTable('package_type', 'composer')
        ->assertCanSeeTableRecords([$this->composerFinding])
        ->assertCanNotSeeTableRecords([$this->npmFinding]);
});
