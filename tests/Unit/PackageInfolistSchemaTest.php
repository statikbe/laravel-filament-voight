<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;

beforeEach(function () {
    $this->actingAs(new User);
});

function packagePage(Package $package): mixed
{
    return Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()]);
}

it('summarises where the package is installed with plurals', function () {
    $package = Package::factory()->create();
    $project = Project::factory()->create();
    $env1 = Environment::factory()->for($project)->create(['name' => 'production']);
    $env2 = Environment::factory()->for($project)->create(['name' => 'staging']);
    EnvironmentPackage::factory()->for($env1)->for($package)->create();
    EnvironmentPackage::factory()->for($env2)->for($package)->create();

    expect(entryState(packagePage($package), 'stat_installed'))->toBe('2 environments · 1 project');
});

it('uses the singular for a single environment and project', function () {
    $package = Package::factory()->create();
    EnvironmentPackage::factory()->for(Environment::factory())->for($package)->create();

    expect(entryState(packagePage($package), 'stat_installed'))->toBe('1 environment · 1 project');
});

it('labels the website entry without repeating the header button', function () {
    $page = packagePage(Package::factory()->create());

    expect($page->instance()->getSchema('infolist')->getComponent('website')->getLabel())->toBe('Website');
});

it('reads None instead of a zero badge when there are no active findings', function () {
    $page = packagePage(Package::factory()->create());

    expect(entryState($page, 'stat_open_findings'))->toBe(voightTrans('fields.none'));
});

it('counts only findings from the latest audit run per environment', function () {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->critical()->create(['vulnerability_score' => 9.5]);

    $oldRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subDays(2)]);
    $newRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subHour()]);

    AuditFinding::factory()->for($oldRun, 'auditRun')->for($package)->for($vuln)->create();
    AuditFinding::factory()->for($newRun, 'auditRun')->for($package)->for($vuln)->create();

    expect(entryState(packagePage($package), 'stat_open_findings'))->toBe('1');
});

it('colours the findings tile by the worst active finding', function (float $score, string $color) {
    $package = Package::factory()->create();
    $run = AuditRun::factory()->for(Environment::factory())->create(['started_at' => now()]);
    AuditFinding::factory()->for($run, 'auditRun')->for($package)->for(Vulnerability::factory()->create(['vulnerability_score' => $score]))->create();

    $entry = packagePage($package)->instance()->getSchema('infolist')->getComponent('stat_open_findings');

    expect($entry->getColor($entry->getState()))->toBe($color);
})->with([[9.5, 'critical'], [5.0, 'warning']]);
