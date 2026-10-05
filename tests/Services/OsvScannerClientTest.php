<?php

use Illuminate\Support\Facades\Http;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Support\CreatedScan;
use Statikbe\FilamentVoight\Support\ScanResponse;
use Statikbe\FilamentVoight\Support\ScanStatus;

beforeEach(function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/locks');
    config()->set('filament-voight.scanner.packages_url', 'https://scanner.test/packages');
    config()->set('filament-voight.scanner.token', 'secret');
});

it('posts packages as json and parses the response', function () {
    Http::fake([
        'scanner.test/packages' => Http::response([
            'batch_id' => 'b1',
            'findings' => [
                ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15',
                    'vulnerability_id' => 'GHSA-x', 'max_severity' => '8.1'],
            ],
            'vulnerabilities' => ['GHSA-x' => ['id' => 'GHSA-x', 'summary' => 's']],
            'summary' => ['skipped_packages' => []],
        ], 200),
    ]);

    $result = app(OsvScannerClient::class)->scanPackages(
        [['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15']],
        'b1',
    );

    expect($result)->toBeInstanceOf(ScanResponse::class)
        ->and($result->findings)->toHaveCount(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://scanner.test/packages'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['batch_id'] === 'b1'
            && $request['packages'][0]['name'] === 'lodash';
    });
});

it('throws on a non-2xx package scan response', function () {
    Http::fake(['scanner.test/packages' => Http::response('boom', 502)]);
    app(OsvScannerClient::class)->scanPackages([], 'b1');
})->throws(RuntimeException::class);

it('creates a scan with type, callback and meta, authenticated', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans/');
    fakeScanner();

    $created = app(OsvScannerClient::class)->createScan(OsvScanType::Packages, ['batch_id' => 'b1'], 'https://voight.test/cb');

    expect($created)->toBeInstanceOf(CreatedScan::class)
        ->and($created->scanId)->toBe('01J9ZK3M4N5P6R7S8T9V0W0001')
        ->and($created->uploadUrl)->toBe('https://s3.test/upload/01J9ZK3M4N5P6R7S8T9V0W0001')
        ->and($created->uploadHeaders)->toBe(['Content-Type' => 'application/json']);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://scanner.test/scans'
        && $request->hasHeader('Authorization', 'Bearer secret')
        && $request['type'] === 'packages'
        && $request['callback_url'] === 'https://voight.test/cb'
        && (array) $request['meta'] === ['batch_id' => 'b1']);
});

it('sends empty meta as a JSON object, not an array', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner();

    app(OsvScannerClient::class)->createScan(OsvScanType::Locks, [], null);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->body(), '"meta":{}'));
});

it('uploads to the presigned url with its headers and without the bearer token', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner();

    app(OsvScannerClient::class)->upload(
        new CreatedScan('S1', 'https://s3.test/upload/S1', ['Content-Type' => 'application/json']),
        '{"packages":[]}',
    );

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://s3.test/upload/S1'
        && $request->body() === '{"packages":[]}'
        && $request->hasHeader('Content-Type', 'application/json')
        && ! $request->hasHeader('Authorization'));
});

it('returns a done status with the downloaded result', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner(['findings' => [['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15', 'vulnerability_id' => 'GHSA-x', 'max_severity' => '8.1']], 'vulnerabilities' => ['GHSA-x' => ['id' => 'GHSA-x']], 'summary' => []]);

    $status = app(OsvScannerClient::class)->getScan('01J9ZK3M4N5P6R7S8T9V0W0001');

    expect($status->isDone())->toBeTrue()
        ->and($status->isTerminal())->toBeTrue()
        ->and($status->result->findings)->toHaveCount(1);

    Http::assertSent(fn ($request) => $request->url() === 'https://s3.test/result/01J9ZK3M4N5P6R7S8T9V0W0001'
        && ! $request->hasHeader('Authorization'));
});

it('returns a failed status with the scanner error', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner(status: 'failed', error: ['code' => 'scan_failed', 'message' => 'boom', 'retryable' => true]);

    $status = app(OsvScannerClient::class)->getScan('S1');

    expect($status->status)->toBe(ScanStatus::FAILED)
        ->and($status->error->code)->toBe('scan_failed')
        ->and($status->error->message)->toBe('boom')
        ->and($status->error->retryable)->toBeTrue()
        ->and($status->result)->toBeNull();
});

it('returns a non-terminal status while the scan runs', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner(status: 'running');

    $status = app(OsvScannerClient::class)->getScan('S1');

    expect($status->status)->toBe('running')->and($status->isTerminal())->toBeFalse();
});

it('treats an unknown scan as a permanent failure', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    Http::fake(['scanner.test/scans/*' => Http::response(['error' => 'Scan not found'], 404)]);

    $status = app(OsvScannerClient::class)->getScan('S1');

    expect($status->status)->toBe(ScanStatus::FAILED)
        ->and($status->error->code)->toBe('not_found')
        ->and($status->error->retryable)->toBeFalse();
});

it('throws on scanner errors so the calling job retries', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    Http::fake(['scanner.test/scans' => Http::response('boom', 503)]);

    app(OsvScannerClient::class)->createScan(OsvScanType::Locks, [], null);
})->throws(RuntimeException::class, 'OSV scanner POST /scans returned HTTP 503');

it('throws when the scanner url is not configured', function () {
    config()->set('filament-voight.scanner.url', null);

    app(OsvScannerClient::class)->getScan('S1');
})->throws(RuntimeException::class, 'OSV scanner URL is not configured. Set VOIGHT_SCANNER_URL.');
