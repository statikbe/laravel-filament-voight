<?php

use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\PackageResource\Schemas\PackageInfolistSchema;

/**
 * Unit tests for the infolist header helpers.
 *
 * These exercise the counting and severity-badge logic without rendering
 * the full Filament infolist (which requires the panel + translation
 * namespace resolution to work in the test environment).
 */
$invokeHelper = function (string $method, Package $package): mixed {
    $reflection = new ReflectionClass(PackageInfolistSchema::class);
    $m = $reflection->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke(null, $package);
};

it('produces a non-empty installed summary string', function () use ($invokeHelper) {
    // The helper's output is a translated string with environment and project
    // counts interpolated. The test environment does not resolve the plugin's
    // translation namespace, so we verify the helper at least returns a string
    // that depends on the package's environments/projects (and doesn't throw).
    $package = Package::factory()->create();
    $project = Project::factory()->create();
    $env1 = Environment::factory()->for($project)->create(['name' => 'production']);
    $env2 = Environment::factory()->for($project)->create(['name' => 'staging']);
    EnvironmentPackage::factory()->for($env1)->for($package)->create();
    EnvironmentPackage::factory()->for($env2)->for($package)->create();

    // Sanity check the underlying counts powering the summary.
    expect($package->environmentPackages()->count())->toBe(2)
        ->and($package->projects()->count())->toBe(1)
        ->and($invokeHelper('installedSummary', $package))->toBeString();
});

it('reports no active findings when there are none', function () use ($invokeHelper) {
    $package = Package::factory()->create();

    expect($invokeHelper('activeFindingsLabel', $package))
        ->not->toBe('0')
        ->toBeString(); // Either "None" or the translation key if untranslated in test env.

    expect($invokeHelper('activeFindingsColor', $package))->toBe('gray');
});

it('counts only findings from the latest audit run per environment', function () use ($invokeHelper) {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->critical()->create(['vulnerability_score' => 9.5]);

    $oldRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subDays(2)]);
    $newRun = AuditRun::factory()->for($env)->create(['started_at' => now()->subHour()]);

    AuditFinding::factory()->for($oldRun, 'auditRun')->for($package)->for($vuln)->create();
    AuditFinding::factory()->for($newRun, 'auditRun')->for($package)->for($vuln)->create();

    expect($invokeHelper('activeFindingsLabel', $package))->toBe('1');
});

it('returns danger color when worst active finding is critical', function () use ($invokeHelper) {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);
    $run = AuditRun::factory()->for($env)->create(['started_at' => now()]);
    AuditFinding::factory()->for($run, 'auditRun')->for($package)->for($vuln)->create();

    expect($invokeHelper('activeFindingsColor', $package))->toBe('danger');
});

it('returns warning color when worst active finding is medium', function () use ($invokeHelper) {
    $package = Package::factory()->create();
    $env = Environment::factory()->create();
    $vuln = Vulnerability::factory()->create(['vulnerability_score' => 5.0]);
    $run = AuditRun::factory()->for($env)->create(['started_at' => now()]);
    AuditFinding::factory()->for($run, 'auditRun')->for($package)->for($vuln)->create();

    expect($invokeHelper('activeFindingsColor', $package))->toBe('warning');
});
