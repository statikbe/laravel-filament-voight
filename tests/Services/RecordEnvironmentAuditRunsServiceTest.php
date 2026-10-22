<?php

use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
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

it('stores separate OSV ranges as a composer parseable || constraint', function () {
    $pkg = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $env = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $env->id, 'package_id' => $pkg->id, 'version' => 'v10.9.0']);

    $raw = ['GHSA-5vg9' => rawVuln('GHSA-5vg9', 'laravel/framework', 'v10.48.29')];
    $raw['GHSA-5vg9']['affected'][0]['ranges'] = [
        ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]],
        ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '11.0.0'], ['last_affected' => '11.2.0']]],
        ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '12.0.0']]],
    ];
    $map = ['composer|laravel/framework|v10.9.0' => [['vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']]];
    $service = app(RecordEnvironmentAuditRunsService::class);

    $service->record($env, $map, $service->upsertVulnerabilities($raw, ['GHSA-5vg9' => '9.1']), $raw, AuditRunTrigger::Nightly);

    expect(VulnerablePackageRange::where('package_id', $pkg->id)->sole()->affected_range)
        ->toBe('>=0 <v10.48.29 || >=11.0.0 <=11.2.0 || >=12.0.0');
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

it('records one finding per vulnerable installed version of the same package', function () {
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $lodash->id, 'version' => '4.17.15']);
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $lodash->id, 'version' => '4.17.11']);

    $raw = ['GHSA-p6mc' => rawVuln('GHSA-p6mc', 'lodash', '4.17.21')];
    $map = [
        'npm|lodash|4.17.15' => [['vulnerability_id' => 'GHSA-p6mc', 'max_severity' => '7.2']],
        'npm|lodash|4.17.11' => [['vulnerability_id' => 'GHSA-p6mc', 'max_severity' => '7.2']],
    ];
    $service = app(RecordEnvironmentAuditRunsService::class);

    $run = $service->record($environment, $map, $service->upsertVulnerabilities($raw, ['GHSA-p6mc' => '7.2']), $raw, AuditRunTrigger::PostSync);

    expect(AuditFinding::where('audit_run_id', $run->id)->pluck('installed_version')->sort()->values()->all())
        ->toBe(['4.17.11', '4.17.15']);
});

it('records a version installed at two nested paths only once', function () {
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->count(2)->create(['environment_id' => $environment->id, 'package_id' => $lodash->id, 'version' => '4.17.11']);

    $raw = ['GHSA-p6mc' => rawVuln('GHSA-p6mc', 'lodash', '4.17.21')];
    $map = ['npm|lodash|4.17.11' => [['vulnerability_id' => 'GHSA-p6mc', 'max_severity' => '7.2']]];
    $service = app(RecordEnvironmentAuditRunsService::class);

    $run = $service->record($environment, $map, $service->upsertVulnerabilities($raw, ['GHSA-p6mc' => '7.2']), $raw, AuditRunTrigger::PostSync);

    expect(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1);
});

it('records one failed run with a truncated message', function () {
    $environment = Environment::factory()->create();

    $run = app(RecordEnvironmentAuditRunsService::class)->recordFailed($environment->id, AuditRunTrigger::PostSync, str_repeat('x', 600));

    expect($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and($run->completed_at)->not->toBeNull()
        ->and(mb_strlen($run->error_message))->toBe(500);
});

it('records failed runs only for environments that still exist', function () {
    $kept = Environment::factory()->create();

    app(RecordEnvironmentAuditRunsService::class)->recordFailedForEnvironments([$kept->id, '01J9ZK3M4N5P6R7S8T9V0WGONE'], AuditRunTrigger::Nightly, 'boom');

    expect(AuditRun::count())->toBe(1)
        ->and(AuditRun::first()->environment_id)->toBe($kept->id);
});

/**
 * @param  array<int, array<int, array<string, string>>>  $ranges  one events list per OSV range
 * @return array<string, mixed>
 */
function rawVulnWithRanges(string $pkgName, array $ranges, string $type = 'SEMVER'): array
{
    return [
        'id' => 'GHSA-multi',
        'summary' => 's',
        'database_specific' => ['severity' => 'HIGH'],
        'affected' => [[
            'package' => ['name' => $pkgName, 'ecosystem' => 'npm'],
            'ranges' => array_map(fn (array $events): array => ['type' => $type, 'events' => $events], $ranges),
        ]],
    ];
}

function fixedVersionFor(string $installed, array $raw, string $name = 'immutable'): ?string
{
    $package = Package::factory()->npm()->create(['name' => $name]);
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $package->id, 'version' => $installed]);

    $service = app(RecordEnvironmentAuditRunsService::class);
    $rawMap = ['GHSA-multi' => $raw];
    $map = ["npm|{$name}|{$installed}" => [['vulnerability_id' => 'GHSA-multi', 'max_severity' => '7.5']]];

    $run = $service->record($environment, $map, $service->upsertVulnerabilities($rawMap, ['GHSA-multi' => '7.5']), $rawMap, AuditRunTrigger::Nightly);

    return AuditFinding::where('audit_run_id', $run->id)->value('fixed_version');
}

it('picks the fixed version of the range containing the installed version', function () {
    $raw = rawVulnWithRanges('immutable', [
        [['introduced' => '0'], ['fixed' => '4.3.8']],
        [['introduced' => '5.0.0-rc.1'], ['fixed' => '5.1.5']],
    ]);

    expect(fixedVersionFor('5.0.3', $raw))->toBe('5.1.5');
});

it('treats introduced 0 as the beginning of time', function () {
    $raw = rawVulnWithRanges('immutable', [
        [['introduced' => '0'], ['fixed' => '4.3.8']],
        [['introduced' => '5.0.0'], ['fixed' => '5.1.5']],
    ]);

    expect(fixedVersionFor('3.8.2', $raw))->toBe('4.3.8');
});

it('returns null when the matching range has no fixed event', function () {
    $raw = rawVulnWithRanges('immutable', [
        [['introduced' => '0'], ['fixed' => '4.3.8']],
        [['introduced' => '5.0.0']],
    ]);

    expect(fixedVersionFor('5.0.3', $raw))->toBeNull();
});

it('returns null when the installed version is above every fix', function () {
    $raw = rawVulnWithRanges('immutable', [
        [['introduced' => '0'], ['fixed' => '4.3.8']],
        [['introduced' => '5.0.0'], ['fixed' => '5.1.5']],
    ]);

    expect(fixedVersionFor('6.0.0', $raw))->toBeNull();
});

it('compares versions with a v prefix', function () {
    $raw = rawVulnWithRanges('laravel/framework', [
        [['introduced' => '10.0.0'], ['fixed' => '10.48.29']],
        [['introduced' => '11.0.0'], ['fixed' => '11.31.0']],
    ], 'ECOSYSTEM');

    expect(fixedVersionFor('v11.2.0', $raw, 'laravel/framework'))->toBe('11.31.0');
});

it('ignores GIT ranges', function () {
    $raw = rawVulnWithRanges('immutable', [
        [['introduced' => '0'], ['fixed' => 'abc123def']],
    ], 'GIT');

    expect(fixedVersionFor('1.0.0', $raw))->toBeNull();
});

it('stores the highest fixed version on the shared package range', function () {
    $raw = rawVulnWithRanges('immutable', [
        [['introduced' => '0'], ['fixed' => '4.3.8']],
        [['introduced' => '5.0.0'], ['fixed' => '5.1.5']],
    ]);

    fixedVersionFor('4.0.0', $raw);

    expect(VulnerablePackageRange::first()->fixed_version)->toBe('5.1.5');
});
