<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;

function createScannableEnvironment(): Environment
{
    $environment = Environment::factory()->create();

    Storage::disk('voight-lockfiles')->put('p/production/composer.lock', '{}');

    DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['p/production/composer.lock'],
        'status' => DependencySyncStatus::Completed,
    ]);

    return $environment;
}

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/locks');
    config()->set('filament-voight.scanner.token', 'secret');

    // Faking only this job leaves RunOsvScanJob itself running synchronously.
    Bus::fake([SendAuditAlertsJob::class]);
});

it('scans one environment via /locks and records findings with the given trigger', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $env = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $env->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);

    Storage::disk('voight-lockfiles')->put('p/production/composer.lock', '{}');
    DependencySync::factory()->for($env)->create([
        'lockfile_paths' => ['p/production/composer.lock'],
        'status' => DependencySyncStatus::Completed,
    ]);

    Http::fake(['scanner.test/locks' => Http::response([
        'summary' => ['skipped_packages' => []],
        'findings' => [
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0',
                'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1'],
        ],
        'vulnerabilities' => ['GHSA-5vg9' => [
            'id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]],
        ]],
    ], 200)]);

    RunOsvScanJob::dispatchSync($env, AuditRunTrigger::PostSync);

    $run = AuditRun::where('environment_id', $env->id)->first();
    expect($run->status)->toBe(AuditRunStatus::Completed)
        ->and($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1);

    Http::assertSent(fn ($request) => $request->url() === 'https://scanner.test/locks');
});

it('defaults the trigger to manual', function () {
    $env = Environment::factory()->create();
    Storage::disk('voight-lockfiles')->put('p/production/composer.lock', '{}');
    DependencySync::factory()->for($env)->create([
        'lockfile_paths' => ['p/production/composer.lock'],
        'status' => DependencySyncStatus::Completed,
    ]);

    Http::fake(['scanner.test/locks' => Http::response([
        'summary' => ['skipped_packages' => []], 'findings' => [], 'vulnerabilities' => [],
    ], 200)]);

    RunOsvScanJob::dispatchSync($env);

    expect(AuditRun::where('environment_id', $env->id)->first()->trigger)->toBe(AuditRunTrigger::Manual);
});

it('dispatches the alerts job with the created run when the scan completes', function () {
    Http::fake(['scanner.test/locks' => Http::response([
        'summary' => ['skipped_packages' => []], 'findings' => [], 'vulnerabilities' => [],
    ], 200)]);

    $environment = createScannableEnvironment();

    RunOsvScanJob::dispatchSync($environment);

    $run = AuditRun::sole();
    expect($run->status)->toBe(AuditRunStatus::Completed);

    Bus::assertDispatched(
        SendAuditAlertsJob::class,
        fn (SendAuditAlertsJob $job): bool => $job->auditRun->is($run),
    );
});

it('does not dispatch the alerts job when the scanner fails', function () {
    Http::fake(['scanner.test/locks' => Http::response('scanner exploded', 500)]);

    $environment = createScannableEnvironment();

    expect(fn () => RunOsvScanJob::dispatchSync($environment))->toThrow(RuntimeException::class);

    expect(AuditRun::sole()->status)->toBe(AuditRunStatus::Failed);
    Bus::assertNotDispatched(SendAuditAlertsJob::class);
});

it('stores why the scan failed on the failed run', function () {
    Http::fake(['scanner.test/locks' => Http::response('scanner exploded', 500)]);

    $environment = createScannableEnvironment();

    expect(fn () => RunOsvScanJob::dispatchSync($environment))->toThrow(RuntimeException::class);

    expect(AuditRun::sole()->error_message)->toContain('500');
});

it('records a finding for a vulnerable npm version installed only in a nested node_modules', function () {
    Http::fake(['scanner.test/locks' => Http::response([
        'summary' => ['skipped_packages' => []],
        'findings' => [
            ['ecosystem' => 'npm', 'name' => 'ms', 'version' => '2.0.0', 'vulnerability_id' => 'GHSA-w9mr', 'max_severity' => '5.3'],
        ],
        'vulnerabilities' => ['GHSA-w9mr' => [
            'id' => 'GHSA-w9mr', 'summary' => 'ms ReDoS', 'database_specific' => ['severity' => 'MODERATE'],
            'affected' => [['package' => ['name' => 'ms'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '2.0.0-fixed']]]]]],
        ]],
    ], 200)]);

    $environment = Environment::factory()->create();
    Storage::disk('voight-lockfiles')->put('p/production/package-lock.json', lockfileFixture('npm/package-lock.json'));
    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['p/production/package-lock.json'],
        'status' => DependencySyncStatus::Pending,
    ]);

    // The sync chains the post-sync scan, which runs synchronously here.
    ProcessLockFilesJob::dispatchSync($sync);

    $finding = AuditFinding::sole();
    expect($finding->package->name)->toBe('ms')
        ->and($finding->installed_version)->toBe('2.0.0')
        ->and($finding->auditRun->environment_id)->toBe($environment->id);
});

it('records a finding for a composer package whose lockfile version has a v prefix', function () {
    // /locks reports the version as written in composer.lock; the parser stores it without the "v".
    Http::fake(['scanner.test/locks' => Http::response([
        'summary' => ['skipped_packages' => []],
        'findings' => [
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0', 'vulnerability_id' => 'GHSA-78fx', 'max_severity' => '7.5'],
        ],
        'vulnerabilities' => ['GHSA-78fx' => [
            'id' => 'GHSA-78fx', 'summary' => 'framework issue', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]],
        ]],
    ], 200)]);

    $environment = Environment::factory()->create();
    Storage::disk('voight-lockfiles')->put('p/production/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework', 'version' => 'v10.9.0']],
    ]));
    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['p/production/composer.lock'],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync);

    $finding = AuditFinding::sole();
    expect($finding->package->name)->toBe('laravel/framework')
        ->and($finding->installed_version)->toBe('10.9.0');
});
