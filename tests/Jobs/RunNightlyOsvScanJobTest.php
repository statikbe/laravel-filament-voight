<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Jobs\RunNightlyOsvScanJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;

beforeEach(function () {
    config()->set('filament-voight.scanner.packages_url', 'https://scanner.test/packages');
    config()->set('filament-voight.scanner.token', 'secret');
});

it('scans only scan_nightly environments and records per-environment audit runs', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $nightly = Environment::factory()->create();
    $optedOut = Environment::factory()->notNightly()->create();

    EnvironmentPackage::factory()->create(['environment_id' => $nightly->id, 'package_id' => $laravel->id, 'version' => 'v10.9.0']);
    EnvironmentPackage::factory()->create(['environment_id' => $optedOut->id, 'package_id' => $laravel->id, 'version' => 'v10.9.0']);

    Http::fake(['scanner.test/packages' => Http::response([
        'batch_id' => 'x',
        'findings' => [
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0',
                'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1'],
        ],
        'vulnerabilities' => ['GHSA-5vg9' => [
            'id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]],
        ]],
        'summary' => ['skipped_packages' => []],
    ], 200)]);

    RunNightlyOsvScanJob::dispatchSync();

    expect(AuditRun::where('environment_id', $nightly->id)->where('trigger', AuditRunTrigger::Nightly)->count())->toBe(1)
        ->and(AuditRun::where('environment_id', $optedOut->id)->count())->toBe(0);

    $run = AuditRun::where('environment_id', $nightly->id)->first();
    expect(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1);
});

it('sends the distinct package set as a single deduplicated batch payload', function () {
    $pkg = Package::factory()->composer()->create(['name' => 'symfony/console']);
    $a = Environment::factory()->create();
    $b = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $a->id, 'package_id' => $pkg->id, 'version' => '7.1.5']);
    EnvironmentPackage::factory()->create(['environment_id' => $b->id, 'package_id' => $pkg->id, 'version' => '7.1.5']);

    Http::fake(['scanner.test/packages' => Http::response([
        'batch_id' => 'x', 'findings' => [], 'vulnerabilities' => [], 'summary' => ['skipped_packages' => []],
    ], 200)]);

    RunNightlyOsvScanJob::dispatchSync();

    Http::assertSent(function ($request) {
        return count($request['packages']) === 1
            && $request['packages'][0]['ecosystem'] === 'Packagist'
            && $request['packages'][0]['name'] === 'symfony/console';
    });
});

it('routes environments with commit-pinned packages to the /locks path and out of the batch', function () {
    // Fake only the inner job so the nightly job itself runs and dispatches it.
    Bus::fake([RunOsvScanJob::class]);

    $pinned = Package::factory()->composer()->create(['name' => 'league/commonmark']);
    $normal = Package::factory()->composer()->create(['name' => 'symfony/console']);

    $pinnedEnv = Environment::factory()->create();
    $dedupeEnv = Environment::factory()->create();

    EnvironmentPackage::factory()->create(['environment_id' => $pinnedEnv->id, 'package_id' => $pinned->id, 'version' => 'dev-main']);
    EnvironmentPackage::factory()->create(['environment_id' => $dedupeEnv->id, 'package_id' => $normal->id, 'version' => '7.1.5']);

    Http::fake(['scanner.test/packages' => Http::response([
        'batch_id' => 'x', 'findings' => [], 'vulnerabilities' => [], 'summary' => ['skipped_packages' => []],
    ], 200)]);

    RunNightlyOsvScanJob::dispatchSync();

    // The pinned environment is dispatched to /locks with the Nightly trigger.
    Bus::assertDispatched(RunOsvScanJob::class, function ($job) use ($pinnedEnv) {
        return $job->environment->id === $pinnedEnv->id && $job->trigger === AuditRunTrigger::Nightly;
    });
    // The dedupe environment is NOT dispatched to /locks.
    Bus::assertNotDispatched(RunOsvScanJob::class, fn ($job) => $job->environment->id === $dedupeEnv->id);

    // The batch payload excludes the commit-pinned environment's packages entirely.
    Http::assertSent(fn ($request) => count($request['packages']) === 1
        && $request['packages'][0]['name'] === 'symfony/console');
});

it('does nothing when there are no scan_nightly environments', function () {
    Environment::factory()->notNightly()->create();

    Http::fake();

    RunNightlyOsvScanJob::dispatchSync();

    Http::assertNothingSent();
    expect(AuditRun::count())->toBe(0);
});
