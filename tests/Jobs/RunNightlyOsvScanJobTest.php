<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Jobs\RunNightlyOsvScanJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
    config()->set('filament-voight.scanner.callback_url', '');
});

function inputOf(OsvScan $scan): array
{
    return json_decode(Storage::disk('voight-lockfiles')->get($scan->input_path), true);
}

it('creates one sweep over the scan_nightly environments with one batch per chunk', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    config()->set('filament-voight.scanner.batch_size', 1);
    $console = Package::factory()->composer()->create(['name' => 'symfony/console']);
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);
    [$a, $b] = Environment::factory()->count(2)->create()->all();
    $optedOut = Environment::factory()->notNightly()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $a->id, 'package_id' => $console->id, 'version' => '7.1.5']);
    EnvironmentPackage::factory()->create(['environment_id' => $b->id, 'package_id' => $console->id, 'version' => '7.1.5']);
    EnvironmentPackage::factory()->create(['environment_id' => $b->id, 'package_id' => $lodash->id, 'version' => '4.17.15']);
    EnvironmentPackage::factory()->create(['environment_id' => $optedOut->id, 'package_id' => $lodash->id, 'version' => '4.17.20']);

    RunNightlyOsvScanJob::dispatchSync();

    $sweep = NightlySweep::sole();
    expect($sweep->status)->toBe(NightlySweepStatus::Scanning)
        ->and(collect($sweep->environment_ids)->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $scans = OsvScan::all();
    expect($scans)->toHaveCount(2) // 2 distinct tuples, batch_size 1
        ->and($scans->every(fn (OsvScan $s) => $s->type === OsvScanType::Packages && $s->nightly_sweep_id === $sweep->id && $s->trigger === AuditRunTrigger::Nightly))->toBeTrue();

    $packages = $scans->flatMap(fn (OsvScan $s) => inputOf($s)['packages'])->sortBy('name')->values()->all();
    expect($packages)->toBe([
        ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15'],
        ['ecosystem' => 'Packagist', 'name' => 'symfony/console', 'version' => '7.1.5'],
    ]);
    expect(inputOf($scans->first())['batch_id'])->toBe($scans->first()->meta['batch_id']);
    Bus::assertDispatchedTimes(SubmitOsvScanJob::class, 2);
});

it('routes environments with commit-pinned packages to the lockfile scan and out of the sweep', function () {
    Bus::fake([RunOsvScanJob::class, SubmitOsvScanJob::class]);
    $pinned = Package::factory()->composer()->create(['name' => 'league/commonmark']);
    $normal = Package::factory()->composer()->create(['name' => 'symfony/console']);
    $pinnedEnv = Environment::factory()->create();
    $dedupeEnv = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $pinnedEnv->id, 'package_id' => $pinned->id, 'version' => 'dev-main']);
    EnvironmentPackage::factory()->create(['environment_id' => $dedupeEnv->id, 'package_id' => $normal->id, 'version' => '7.1.5']);

    RunNightlyOsvScanJob::dispatchSync();

    Bus::assertDispatched(RunOsvScanJob::class, fn ($job) => $job->environment->id === $pinnedEnv->id && $job->trigger === AuditRunTrigger::Nightly);
    Bus::assertNotDispatched(RunOsvScanJob::class, fn ($job) => $job->environment->id === $dedupeEnv->id);
    expect(NightlySweep::sole()->environment_ids)->toBe([$dedupeEnv->id])
        ->and(inputOf(OsvScan::sole())['packages'])->toBe([['ecosystem' => 'Packagist', 'name' => 'symfony/console', 'version' => '7.1.5']]);
});

it('does nothing when there are no scan_nightly environments', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    Environment::factory()->notNightly()->create();

    RunNightlyOsvScanJob::dispatchSync();

    expect(NightlySweep::count())->toBe(0)->and(OsvScan::count())->toBe(0);
});

it('leaves no partial sweep when creating a batch fails halfway', function () {
    // Real sync queue: it honours afterCommit and drops the queued submissions on rollback.
    Http::fake();
    config()->set('filament-voight.scanner.batch_size', 1);
    foreach (['a/one', 'b/two'] as $name) {
        $package = Package::factory()->composer()->create(['name' => $name]);
        EnvironmentPackage::factory()->create(['environment_id' => Environment::factory()->create()->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    }

    $calls = 0;
    $real = app(OsvScanSubmitter::class);
    app()->instance(OsvScanSubmitter::class, Mockery::mock(OsvScanSubmitter::class, function ($mock) use (&$calls, $real) {
        $mock->shouldReceive('start')->andReturnUsing(function (...$args) use (&$calls, $real) {
            if (++$calls === 2) {
                throw new RuntimeException('disk full');
            }

            return $real->start(...$args);
        });
    }));

    expect(fn () => app()->call([new RunNightlyOsvScanJob, 'handle']))->toThrow(RuntimeException::class, 'disk full');

    expect(NightlySweep::count())->toBe(0)
        ->and(OsvScan::count())->toBe(0);
    Http::assertNothingSent();
});

it('records every sweep environment end to end through submit, poll, completion and recording', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $nightly = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $nightly->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    fakeScanner([
        'findings' => [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => '10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']],
        'vulnerabilities' => ['GHSA-5vg9' => ['id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
        'summary' => ['skipped_packages' => []],
    ]);

    RunNightlyOsvScanJob::dispatchSync();

    $run = AuditRun::where('environment_id', $nightly->id)->sole();
    expect($run->trigger)->toBe(AuditRunTrigger::Nightly)
        ->and($run->status)->toBe(AuditRunStatus::Completed)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1)
        ->and(NightlySweep::sole()->status)->toBe(NightlySweepStatus::Completed);
});

it('records a failed nightly run for every scan_nightly environment when the job itself fails', function () {
    $nightly = Environment::factory()->count(2)->create();
    Environment::factory()->notNightly()->create();

    (new RunNightlyOsvScanJob)->failed(new RuntimeException('database unavailable'));

    expect(AuditRun::count())->toBe(2)
        ->and(AuditRun::all()->every(fn (AuditRun $run) => $run->status === AuditRunStatus::Failed && $run->error_message === 'database unavailable'))->toBeTrue();
});
