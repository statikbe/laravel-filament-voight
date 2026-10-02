<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
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
    EnvironmentPackage::factory()->create(['environment_id' => $env->id, 'package_id' => $laravel->id, 'version' => 'v10.9.0']);

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
