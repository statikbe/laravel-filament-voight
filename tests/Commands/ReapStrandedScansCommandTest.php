<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\PollOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
});

it('fails a locks scan left pending or processing past the threshold with one failed run', function (OsvScanStatus $status) {
    $scan = OsvScan::factory()->create(['status' => $status]);
    $this->travel(121)->minutes();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and($scan->error_message)->toBe('scan abandoned: no result after 120 minutes')
        ->and(AuditRun::sole())
        ->environment_id->toBe($scan->environment_id)
        ->status->toBe(AuditRunStatus::Failed)
        ->trigger->toBe(AuditRunTrigger::Manual)
        ->error_message->toBe('scan abandoned: no result after 120 minutes');
})->with([
    'pending' => OsvScanStatus::Pending,
    'processing' => OsvScanStatus::Processing,
]);

it('fails a stranded batch and its sweep, recording a failed run per sweep environment', function () {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['environment_ids' => $environments->pluck('id')->all()]);
    $stranded = OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id]);
    OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    $this->travel(121)->minutes();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();

    expect($stranded->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed)
        ->and(AuditRun::where('status', AuditRunStatus::Failed)->where('trigger', AuditRunTrigger::Nightly)->count())->toBe(2)
        ->and(AuditRun::first()->error_message)->toBe('Nightly scan batch failed: scan abandoned: no result after 120 minutes');
});

it('fails a sweep left scanning or recording past the threshold and records a failed run per environment', function (NightlySweepStatus $status) {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['status' => $status, 'environment_ids' => $environments->pluck('id')->all()]);
    $this->travel(361)->minutes();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();

    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed)
        ->and($sweep->completed_at)->not->toBeNull()
        ->and(AuditRun::where('status', AuditRunStatus::Failed)->where('trigger', AuditRunTrigger::Nightly)->pluck('environment_id')->sort()->values()->all())
        ->toBe($environments->pluck('id')->sort()->values()->all())
        ->and(AuditRun::first()->error_message)->toBe('nightly sweep abandoned: not finished after 360 minutes');
})->with([
    'scanning' => NightlySweepStatus::Scanning,
    'recording' => NightlySweepStatus::Recording,
]);

it('leaves scans and sweeps under their thresholds alone', function () {
    $scan = OsvScan::factory()->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [Environment::factory()->create()->id]]);
    $this->travel(119)->minutes();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Pending)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Recording)
        ->and(AuditRun::count())->toBe(0);
});

it('uses the configured thresholds', function () {
    config()->set('filament-voight.scanner.reap_scans_after_minutes', 10);
    config()->set('filament-voight.scanner.reap_sweeps_after_minutes', 20);
    $scan = OsvScan::factory()->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording]);
    $this->travel(21)->minutes();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();

    expect($scan->refresh()->error_message)->toBe('scan abandoned: no result after 10 minutes')
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed);
});

it('leaves finished scans and sweeps alone', function () {
    $scan = OsvScan::factory()->create(['status' => OsvScanStatus::Completed]);
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Completed, 'environment_ids' => [Environment::factory()->create()->id]]);
    $this->travel(1)->days();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Completed)
        ->and(AuditRun::count())->toBe(0);
});

it('does nothing to a scan a real completion claimed after the reaper loaded it', function () {
    Bus::fake([SendAuditAlertsJob::class]);
    fakeScanner();
    $scan = OsvScan::factory()->create();
    $this->travel(121)->minutes();
    $staleCopy = OsvScan::find($scan->id);

    // The poll completes the scan between the reaper's read and its claim.
    $poll = (new PollOsvScanJob($scan, (string) $scan->scanner_scan_id))->withFakeQueueInteractions();
    app()->call([$poll, 'handle']);
    $claimed = app(OsvScanResultHandler::class)->failAbandoned($staleCopy, now()->subMinutes(120), 'scan abandoned: no result after 120 minutes');

    expect($claimed)->toBeFalse()
        ->and($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and(AuditRun::sole()->status)->toBe(AuditRunStatus::Completed);
});

it('does nothing to a scan claimed by a live completion that is still recording', function () {
    $scan = OsvScan::factory()->create();
    $this->travel(121)->minutes();
    $staleCopy = OsvScan::find($scan->id);
    OsvScan::whereKey($scan->id)->update(['status' => OsvScanStatus::Processing]); // a fresh claim

    $claimed = app(OsvScanResultHandler::class)->failAbandoned($staleCopy, now()->subMinutes(120), 'scan abandoned: no result after 120 minutes');

    expect($claimed)->toBeFalse()
        ->and($scan->refresh()->status)->toBe(OsvScanStatus::Processing)
        ->and(AuditRun::count())->toBe(0);
});

it('makes a late poll a no-op once the reaper failed the scan', function () {
    fakeScanner();
    $scan = OsvScan::factory()->create();
    $this->travel(121)->minutes();

    $this->artisan('voight:reap-stranded-scans')->assertSuccessful();
    $poll = (new PollOsvScanJob($scan, (string) $scan->scanner_scan_id))->withFakeQueueInteractions();
    app()->call([$poll, 'handle']);

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::sole()->status)->toBe(AuditRunStatus::Failed);
});

it('is scheduled hourly, also when the nightly scan is disabled', function () {
    config()->set('filament-voight.scanner.nightly_cron', '');
    app()->forgetInstance(Schedule::class);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'voight:reap-stranded-scans'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
