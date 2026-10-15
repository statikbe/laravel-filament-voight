<?php

use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\RecordNightlySweepJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;

beforeEach(function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
});

function laravelVulnerabilityResult(): array
{
    return [
        'findings' => [
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => '10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1'],
        ],
        'vulnerabilities' => ['GHSA-5vg9' => [
            'id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]],
        ]],
        'summary' => ['skipped_packages' => []],
    ];
}

it('records every sweep environment from the merged batch results and completes the sweep', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    [$a, $b] = Environment::factory()->count(2)->create()->all();
    foreach ([$a, $b] as $environment) {
        EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    }
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [$a->id, $b->id]]);
    OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner(laravelVulnerabilityResult());

    RecordNightlySweepJob::dispatchSync($sweep);

    expect(AuditRun::where('trigger', AuditRunTrigger::Nightly)->where('status', AuditRunStatus::Completed)->count())->toBe(2)
        ->and(AuditFinding::count())->toBe(2)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Completed)
        ->and($sweep->completed_at)->not->toBeNull();
});

it('skips environments deleted since the sweep started', function () {
    $kept = Environment::factory()->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [$kept->id, '01J9ZK3M4N5P6R7S8T9V0WGONE']]);
    OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner();

    RecordNightlySweepJob::dispatchSync($sweep);

    expect(AuditRun::pluck('environment_id')->all())->toBe([$kept->id]);
});

it('throws, recording nothing, when a batch result is no longer available', function () {
    $environment = Environment::factory()->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [$environment->id]]);
    OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner(status: 'failed', error: ['code' => 'not_found', 'message' => 'gone', 'retryable' => false]);

    expect(fn () => app()->call([new RecordNightlySweepJob($sweep), 'handle']))->toThrow(RuntimeException::class);
    expect(AuditRun::count())->toBe(0);
});

it('marks the sweep failed and records a failed run per environment once retries are exhausted', function () {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => $environments->pluck('id')->all()]);

    (new RecordNightlySweepJob($sweep))->failed(new RuntimeException('db down'));
    (new RecordNightlySweepJob($sweep))->failed(new RuntimeException('db down')); // second call is a no-op

    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed)
        ->and(AuditRun::where('status', AuditRunStatus::Failed)->count())->toBe(2)
        ->and(AuditRun::first()->error_message)->toBe('Recording the nightly scan failed: db down');
});

it('records a sweep once when the job runs twice', function () {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => $environments->pluck('id')->all()]);
    OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner();

    // A duplicate delivery (retry_after expiring mid-run) still holds the old in-memory sweep.
    app()->call([new RecordNightlySweepJob($sweep), 'handle']);
    app()->call([new RecordNightlySweepJob($sweep), 'handle']);

    expect(AuditRun::count())->toBe(2)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Completed);
});
