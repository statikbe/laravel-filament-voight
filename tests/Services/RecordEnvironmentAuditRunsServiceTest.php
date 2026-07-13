<?php

use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;

/**
 * @return array<string, mixed>
 */
function rawVuln(string $id, string $pkgName, string $fixed): array
{
    return [
        'id' => $id,
        'aliases' => ['CVE-x'],
        'summary' => 'summary of ' . $id,
        'details' => 'details',
        'published' => '2021-02-15T16:13:26Z',
        'modified' => '2024-03-15T09:12:01Z',
        'database_specific' => ['severity' => 'HIGH'],
        'affected' => [[
            'package' => ['name' => $pkgName, 'ecosystem' => 'Packagist'],
            'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => $fixed]]]],
        ]],
    ];
}

it('maps a finding to exactly the environments carrying that version', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $vulnerable = Environment::factory()->create();   // v10.9.0 -> affected
    $safe = Environment::factory()->create();          // v10.48.16 -> not in map

    EnvironmentPackage::factory()->create(['environment_id' => $vulnerable->id, 'package_id' => $laravel->id, 'version' => 'v10.9.0']);
    EnvironmentPackage::factory()->create(['environment_id' => $safe->id, 'package_id' => $laravel->id, 'version' => 'v10.48.16']);

    $raw = ['GHSA-5vg9' => rawVuln('GHSA-5vg9', 'laravel/framework', 'v10.48.29')];
    $service = app(RecordEnvironmentAuditRunsService::class);
    $models = $service->upsertVulnerabilities($raw, ['GHSA-5vg9' => '9.1']);

    $map = ['composer|laravel/framework|v10.9.0' => [['vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']]];

    $runVuln = $service->record($vulnerable, $map, $models, $raw, AuditRunTrigger::Nightly);
    $runSafe = $service->record($safe, $map, $models, $raw, AuditRunTrigger::Nightly);

    expect($runVuln->status)->toBe(AuditRunStatus::Completed)
        ->and($runVuln->trigger)->toBe(AuditRunTrigger::Nightly)
        ->and(AuditFinding::where('audit_run_id', $runVuln->id)->count())->toBe(1)
        ->and(AuditFinding::where('audit_run_id', $runSafe->id)->count())->toBe(0);

    $finding = AuditFinding::where('audit_run_id', $runVuln->id)->first();
    expect($finding->installed_version)->toBe('v10.9.0')
        ->and($finding->fixed_version)->toBe('v10.48.29')
        ->and($vulnerable->refresh()->scanned_at)->not->toBeNull();

    // A VulnerablePackageRange is recorded for the (vuln, package) pair.
    expect(VulnerablePackageRange::where('package_id', $laravel->id)->count())->toBe(1);
});

it('scores from max_severity when present', function () {
    $raw = ['GHSA-a' => rawVuln('GHSA-a', 'league/commonmark', '1.0.1')];
    $models = app(RecordEnvironmentAuditRunsService::class)
        ->upsertVulnerabilities($raw, ['GHSA-a' => '7.5']);

    expect((float) $models['GHSA-a']->refresh()->vulnerability_score)->toBe(7.5);
});

it('falls back to the database_specific severity label when max_severity is null', function () {
    $raw = ['GHSA-b' => rawVuln('GHSA-b', 'league/commonmark', '1.0.1')];
    $models = app(RecordEnvironmentAuditRunsService::class)
        ->upsertVulnerabilities($raw, ['GHSA-b' => null]);

    // 'HIGH' -> Severity::High representative score (> 0)
    expect((float) $models['GHSA-b']->refresh()->vulnerability_score)->toBeGreaterThan(0.0);
});

it('is idempotent across repeated runs for the same environment', function () {
    $pkg = Package::factory()->composer()->create(['name' => 'league/commonmark']);
    $env = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $env->id, 'package_id' => $pkg->id, 'version' => '1.0.0']);

    $raw = ['GHSA-a' => rawVuln('GHSA-a', 'league/commonmark', '1.0.1')];
    $map = ['composer|league/commonmark|1.0.0' => [['vulnerability_id' => 'GHSA-a', 'max_severity' => '7.5']]];
    $service = app(RecordEnvironmentAuditRunsService::class);

    $service->record($env, $map, $service->upsertVulnerabilities($raw, ['GHSA-a' => '7.5']), $raw, AuditRunTrigger::Nightly);
    $service->record($env, $map, $service->upsertVulnerabilities($raw, ['GHSA-a' => '7.5']), $raw, AuditRunTrigger::Nightly);

    // Two runs, but the range stays unique per (vuln, package).
    expect(VulnerablePackageRange::where('package_id', $pkg->id)->count())->toBe(1);
});
