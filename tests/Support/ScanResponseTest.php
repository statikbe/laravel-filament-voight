<?php

use Statikbe\FilamentVoight\Support\ScanResponse;

$payload = [
    'findings' => [
        ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15',
            'vulnerability_id' => 'GHSA-35jh-r3h4-6jhm', 'max_severity' => '8.1'],
        ['ecosystem' => 'Packagist', 'name' => 'symfony/http-kernel', 'version' => '5.4.0',
            'vulnerability_id' => 'GHSA-h7vf-5wrv-9fhv', 'max_severity' => null],
    ],
    'vulnerabilities' => [
        'GHSA-35jh-r3h4-6jhm' => ['id' => 'GHSA-35jh-r3h4-6jhm', 'summary' => 'x'],
        'GHSA-h7vf-5wrv-9fhv' => ['id' => 'GHSA-h7vf-5wrv-9fhv', 'summary' => 'y'],
    ],
    'summary' => ['skipped_packages' => [
        ['ecosystem' => 'Packagist', 'name' => 'league/commonmark', 'version' => 'dev-main',
            'reason' => 'unsupported_version_format'],
    ]],
];

it('parses the unified response', function () use ($payload) {
    $r = ScanResponse::fromArray($payload);
    expect($r->findings)->toHaveCount(2)
        ->and($r->vulnerabilities)->toHaveKey('GHSA-35jh-r3h4-6jhm')
        ->and($r->skippedPackages)->toHaveCount(1);
});

it('indexes findings by composer/npm package key', function () use ($payload) {
    $map = ScanResponse::fromArray($payload)->findingsByPackageKey();
    expect($map)->toHaveKey('npm|lodash|4.17.15')
        ->and($map)->toHaveKey('composer|symfony/http-kernel|5.4.0')
        ->and($map['npm|lodash|4.17.15'][0]['max_severity'])->toBe('8.1');
});
