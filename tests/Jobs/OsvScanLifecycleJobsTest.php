<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\CompleteOsvScanJob;
use Statikbe\FilamentVoight\Jobs\PollOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\OsvScan;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
    config()->set('filament-voight.scanner.callback_url', 'https://voight.test/api/voight/scans/callback');
    config()->set('filament-voight.scanner.poll_interval', 15);
});

function pendingScanWithInput(): OsvScan
{
    Storage::disk('voight-lockfiles')->put('voight/scans/s/input.json', '{"files":[]}');

    return OsvScan::factory()->create(['scanner_scan_id' => null, 'input_path' => 'voight/scans/s/input.json']);
}

it('submit creates the remote scan, uploads the saved input and schedules a poll', function () {
    Bus::fake([PollOsvScanJob::class]);
    fakeScanner();
    $scan = pendingScanWithInput();

    SubmitOsvScanJob::dispatchSync($scan);

    expect($scan->refresh()->scanner_scan_id)->toBe('01J9ZK3M4N5P6R7S8T9V0W0001');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['callback_url'] === 'https://voight.test/api/voight/scans/callback' && $r['type'] === 'locks');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->body() === '{"files":[]}');
    Bus::assertDispatched(PollOsvScanJob::class, fn ($job) => $job->scannerScanId === '01J9ZK3M4N5P6R7S8T9V0W0001' && $job->delay !== null);
});

it('submit does nothing for a scan that is no longer pending', function () {
    Http::fake();
    $scan = pendingScanWithInput();
    $scan->update(['status' => OsvScanStatus::Completed]);

    SubmitOsvScanJob::dispatchSync($scan);

    Http::assertNothingSent();
});

it('submit failing for good records a failed run', function () {
    $scan = pendingScanWithInput();

    (new SubmitOsvScanJob($scan))->failed(new RuntimeException('scanner unreachable'));

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('Submitting the scan failed: scanner unreachable');
});

it('poll releases while the scan is running', function () {
    fakeScanner(status: 'running');
    $scan = OsvScan::factory()->create();

    $job = (new PollOsvScanJob($scan, $scan->scanner_scan_id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 15);
});

it('poll hands a terminal status to the handler', function () {
    Bus::fake([SendAuditAlertsJob::class]);
    fakeScanner();
    $scan = OsvScan::factory()->create();

    $job = (new PollOsvScanJob($scan, $scan->scanner_scan_id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and(AuditRun::sole()->status)->toBe(AuditRunStatus::Completed);
});

it('poll stops without calling the scanner once its scanner scan id was replaced', function () {
    Http::fake();
    $scan = OsvScan::factory()->create();

    $job = (new PollOsvScanJob($scan, '01J9ZK3M4N5P6R7S8T9V0WOLD1'))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    $job->assertNotReleased();
});

it('poll deadline records a failed run', function () {
    $scan = OsvScan::factory()->create();

    (new PollOsvScanJob($scan, $scan->scanner_scan_id))->failed(new RuntimeException('max attempts'));

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('Polling the scan failed: max attempts');
});

it('poll past its deadline records "poll deadline exceeded"', function () {
    $scan = OsvScan::factory()->create();

    (new PollOsvScanJob($scan, $scan->scanner_scan_id))->failed(new MaxAttemptsExceededException('PollOsvScanJob has been attempted too many times.'));

    expect($scan->refresh()->error_message)->toBe('poll deadline exceeded')
        ->and(AuditRun::sole()->error_message)->toBe('poll deadline exceeded');
});

it('poll timing out is not reported as the poll deadline', function () {
    $scan = OsvScan::factory()->create();

    (new PollOsvScanJob($scan, $scan->scanner_scan_id))->failed(new TimeoutExceededException('PollOsvScanJob has timed out.'));

    expect(AuditRun::sole()->error_message)->toBe('Polling the scan failed: PollOsvScanJob has timed out.');
});

it('poll fails once the deadline passed when the worker picks it up again', function () {
    $scan = OsvScan::factory()->create();
    $job = new PollOsvScanJob($scan, $scan->scanner_scan_id);
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => PollOsvScanJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 0,
        'retryUntil' => now()->subSecond()->getTimestamp(),
        'data' => ['commandName' => PollOsvScanJob::class, 'command' => serialize($job)],
    ]);
    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('getConnectionName')->andReturn('fake');
    $queue->shouldReceive('pop')->andReturn(new SyncJob(app(), $payload, 'fake', 'default'), null);
    $manager = Mockery::mock(QueueManager::class);
    $manager->shouldReceive('connection')->andReturn($queue);
    $manager->shouldReceive('getName')->andReturn('fake');
    $worker = new Worker($manager, app('events'), app(ExceptionHandler::class), fn () => false);

    $worker->runNextJob('fake', 'default', new WorkerOptions);

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('poll deadline exceeded');
});

it('complete ignores a non-terminal status, so an early or forged callback does nothing', function () {
    fakeScanner(status: 'running');
    $scan = OsvScan::factory()->create();

    CompleteOsvScanJob::dispatchSync($scan, $scan->scanner_scan_id);

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Pending);
});

it('drops queued lifecycle jobs whose scan was deleted', function (string $jobClass) {
    $scan = OsvScan::factory()->create();
    $job = new $jobClass($scan, $scan->scanner_scan_id); // SubmitOsvScanJob ignores the second argument
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $job::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => null,
        'data' => ['commandName' => $job::class, 'command' => serialize($job)],
    ]);
    $scan->environment->delete(); // cascades to the scan

    $queued = new SyncJob(app(), $payload, 'sync', 'default');
    $queued->fire();

    expect(OsvScan::count())->toBe(0)
        ->and($queued->isDeleted())->toBeTrue()
        ->and($queued->hasFailed())->toBeFalse();
})->with([
    'submit' => SubmitOsvScanJob::class,
    'poll' => PollOsvScanJob::class,
    'complete' => CompleteOsvScanJob::class,
]);

it('polls until the 30-minute deadline', function () {
    $scan = OsvScan::factory()->create();
    $job = new PollOsvScanJob($scan, $scan->scanner_scan_id);

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(29)->getTimestamp())
        ->and($job->tries)->toBe(0);
});
