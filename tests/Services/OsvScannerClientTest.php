<?php

use Illuminate\Support\Facades\Http;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Support\ScanResponse;

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
