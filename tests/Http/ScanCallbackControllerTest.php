<?php

use Illuminate\Support\Facades\Bus;
use Statikbe\FilamentVoight\Jobs\CompleteOsvScanJob;
use Statikbe\FilamentVoight\Models\OsvScan;

beforeEach(function () {
    config()->set('filament-voight.scanner.callback_secret', 'cb-secret');
    Bus::fake([CompleteOsvScanJob::class]);
});

/**
 * @param  array<string, mixed>  $payload
 */
function signedCallback(array $payload, ?int $timestamp = null, string $secret = 'cb-secret')
{
    $timestamp ??= time();
    $body = json_encode($payload);

    return test()->withHeaders([
        'X-Voight-Timestamp' => (string) $timestamp,
        'X-Voight-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
    ])->postJson('/api/voight/scans/callback', $payload);
}

it('queues completion for a correctly signed callback', function () {
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'])->assertStatus(202);

    Bus::assertDispatched(CompleteOsvScanJob::class, fn ($job) => $job->scan->is($scan) && $job->scannerScanId === $scan->scanner_scan_id);
});

it('rejects a wrong signature', function () {
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'], secret: 'wrong')->assertStatus(401);

    Bus::assertNotDispatched(CompleteOsvScanJob::class);
});

it('rejects a timestamp outside five minutes', function () {
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'], timestamp: time() - 301)->assertStatus(401);
    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'], timestamp: time() + 301)->assertStatus(401);
});

it('rejects every callback when no secret is configured', function () {
    config()->set('filament-voight.scanner.callback_secret', null);
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'])->assertStatus(401);
});

it('rejects a callback without signature headers', function () {
    $this->postJson('/api/voight/scans/callback', ['scan_id' => 'x'])->assertStatus(401);
});

it('returns 404 for an unknown scan', function () {
    signedCallback(['scan_id' => '01J9ZK3M4N5P6R7S8T9V0WNONE', 'status' => 'done'])->assertStatus(404);

    Bus::assertNotDispatched(CompleteOsvScanJob::class);
});

it('returns 404, not a server error, for a scan_id that is not a string', function (array $payload) {
    OsvScan::factory()->create(['scanner_scan_id' => '123']);

    signedCallback($payload)->assertStatus(404);

    Bus::assertNotDispatched(CompleteOsvScanJob::class);
})->with([
    'array' => [['scan_id' => ['123'], 'status' => 'done']],
    'number' => [['scan_id' => 123, 'status' => 'done']],
    'null' => [['scan_id' => null, 'status' => 'done']],
    'missing' => [['status' => 'done']],
]);

it('does not require the project token API middleware', function () {
    // TestCase sets api.middleware to ['auth']; an unauthenticated signed call must still pass.
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'failed'])->assertStatus(202);
});

it('agrees with the scanner implementation on a known signature vector', function () {
    // Vector produced by the lambda sender's algorithm (verified with openssl).
    expect('sha256=' . hash_hmac('sha256', '1759658400.{"a":1}', 'k'))
        ->toBe('sha256=524dd8efed8d924a9b5c6f9f6edeab8264ff488aa353ec5920ad6f1abb70cda7');
});
