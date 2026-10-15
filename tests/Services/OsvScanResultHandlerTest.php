<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\RecordNightlySweepJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;
use Statikbe\FilamentVoight\Support\ScanError;
use Statikbe\FilamentVoight\Support\ScanResponse;
use Statikbe\FilamentVoight\Support\ScanStatus;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    Bus::fake([SendAuditAlertsJob::class, SubmitOsvScanJob::class, RecordNightlySweepJob::class]);
});

function doneScanStatus(array $findings = [], array $vulnerabilities = []): ScanStatus
{
    return new ScanStatus(ScanStatus::DONE, ScanResponse::fromArray(['findings' => $findings, 'vulnerabilities' => $vulnerabilities]));
}

function failedScanStatus(string $code = 'scan_failed', bool $retryable = true): ScanStatus
{
    return new ScanStatus(ScanStatus::FAILED, error: new ScanError($code, 'boom', $retryable));
}

function scanResultHandler(): OsvScanResultHandler
{
    return app(OsvScanResultHandler::class);
}

it('records a done locks scan once, dispatches alerts, deletes its input', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    Storage::disk('voight-lockfiles')->put('voight/scans/x/input.json', '{}');
    $scan = OsvScan::factory()->create(['environment_id' => $environment->id, 'trigger' => AuditRunTrigger::PostSync, 'input_path' => 'voight/scans/x/input.json']);

    $status = doneScanStatus(
        [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']],
        ['GHSA-5vg9' => ['id' => 'GHSA-5vg9', 'summary' => 's', 'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
    );

    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, $status);
    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, $status); // callback + poller race

    $run = AuditRun::sole();
    expect($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and($run->status)->toBe(AuditRunStatus::Completed)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1)
        ->and($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and($scan->input_path)->toBeNull();
    Storage::disk('voight-lockfiles')->assertMissing('voight/scans/x/input.json');
    Bus::assertDispatchedTimes(SendAuditAlertsJob::class, 1);
});

it('ignores a terminal status for a scanner scan id that a resubmit replaced', function () {
    $scan = OsvScan::factory()->create(['scanner_scan_id' => '01J9ZK3M4N5P6R7S8T9V0WNEW1']);

    scanResultHandler()->handleTerminal($scan, '01J9ZK3M4N5P6R7S8T9V0WOLD1', doneScanStatus());
    scanResultHandler()->failForGood($scan, 'poll deadline exceeded', '01J9ZK3M4N5P6R7S8T9V0WOLD1');

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Pending)
        ->and(AuditRun::count())->toBe(0);
});

it('resubmits a retryable failure until max_resubmits, then records one failed run', function () {
    config()->set('filament-voight.scanner.max_resubmits', 2);
    $scan = OsvScan::factory()->create(['trigger' => AuditRunTrigger::Manual]);

    foreach ([1, 2] as $attempt) {
        scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, failedScanStatus());
        $scan->refresh();
        expect($scan->status)->toBe(OsvScanStatus::Pending)
            ->and($scan->resubmits)->toBe($attempt)
            ->and($scan->scanner_scan_id)->toBeNull();
        $scan->update(['scanner_scan_id' => (string) Str::ulid()]); // what SubmitOsvScanJob does
    }

    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, failedScanStatus());

    Bus::assertDispatchedTimes(SubmitOsvScanJob::class, 2);
    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and($scan->error_message)->toBe('scan_failed: boom')
        ->and(AuditRun::sole()->status)->toBe(AuditRunStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('scan_failed: boom');
});

it('does not resubmit a non-retryable failure', function () {
    $scan = OsvScan::factory()->create();

    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, failedScanStatus('invalid_input', false));

    Bus::assertNotDispatched(SubmitOsvScanJob::class);
    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::count())->toBe(1);
});

it('completes a batch and starts recording only when the last batch of the sweep completes', function () {
    $sweep = NightlySweep::factory()->create();
    [$first, $second] = OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id])->all();

    scanResultHandler()->handleTerminal($first, $first->scanner_scan_id, doneScanStatus());
    Bus::assertNotDispatched(RecordNightlySweepJob::class);
    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Scanning);

    scanResultHandler()->handleTerminal($second, $second->scanner_scan_id, doneScanStatus());
    scanResultHandler()->handleTerminal($second, $second->scanner_scan_id, doneScanStatus());

    Bus::assertDispatchedTimes(RecordNightlySweepJob::class, 1);
    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Recording);
});

it('fails the sweep once, records failed runs for its environments, and never resubmits its other batches', function () {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['environment_ids' => $environments->pluck('id')->all()]);
    [$first, $second] = OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id])->all();

    scanResultHandler()->handleTerminal($first, $first->scanner_scan_id, failedScanStatus('invalid_input', false));
    scanResultHandler()->handleTerminal($second, $second->scanner_scan_id, failedScanStatus()); // retryable, but the sweep already failed

    Bus::assertNotDispatched(SubmitOsvScanJob::class);
    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed)
        ->and(AuditRun::where('status', AuditRunStatus::Failed)->count())->toBe(2)
        ->and(AuditRun::first()->error_message)->toBe('Nightly scan batch failed: invalid_input: boom')
        ->and($second->refresh()->status)->toBe(OsvScanStatus::Failed);
});

it('ignores late completions of batches in a failed sweep', function () {
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Failed]);
    $late = OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id]);

    scanResultHandler()->handleTerminal($late, $late->scanner_scan_id, doneScanStatus());

    Bus::assertNotDispatched(RecordNightlySweepJob::class);
    expect($late->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed);
});

it('failForGood only acts on a pending scan', function () {
    $scan = OsvScan::factory()->create(['status' => OsvScanStatus::Completed]);

    scanResultHandler()->failForGood($scan, 'too late');

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and(AuditRun::count())->toBe(0);
});

it('rolls back a half-recorded locks scan and records only the failed run', function () {
    app()->instance(RecordEnvironmentAuditRunsService::class, new class extends RecordEnvironmentAuditRunsService
    {
        public function record(Environment $environment, array $findingsMap, array $vulnerabilityModels, array $rawVulnerabilities, AuditRunTrigger $trigger): AuditRun
        {
            parent::record($environment, $findingsMap, $vulnerabilityModels, $rawVulnerabilities, $trigger);

            throw new RuntimeException('db down');
        }
    });
    $scan = OsvScan::factory()->create();

    expect(fn () => scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, doneScanStatus()))
        ->toThrow(RuntimeException::class, 'db down');

    Bus::assertNotDispatched(SendAuditAlertsJob::class);
    expect(AuditRun::sole()->status)->toBe(AuditRunStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('Recording the scan result failed: db down')
        ->and($scan->refresh()->status)->toBe(OsvScanStatus::Failed);
});

it('truncates the recorded error message to 500 characters', function () {
    $scan = OsvScan::factory()->create();

    scanResultHandler()->failForGood($scan, str_repeat('x', 800));

    expect(mb_strlen((string) $scan->refresh()->error_message))->toBe(500)
        ->and(mb_strlen((string) AuditRun::sole()->error_message))->toBe(500);
});
