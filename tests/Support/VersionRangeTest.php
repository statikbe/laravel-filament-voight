<?php

use Statikbe\FilamentVoight\Support\VersionRange;

it('keeps only versions inside an advisory style range', function () {
    $versions = ['10.47.0', '10.48.28', '10.48.29', '11.0.0'];

    expect(VersionRange::filterSatisfying($versions, '>=10.0.0,<10.48.29'))
        ->toBe(['10.47.0', '10.48.28']);
});

it('understands space separated and || ranges as used by npm advisories', function () {
    $versions = ['0.9.0', '1.6.3', '1.6.4', '2.0.5', '2.1.1'];

    expect(VersionRange::filterSatisfying($versions, '<1.6.4 || >=2.0.0 <2.1.1'))
        ->toBe(['0.9.0', '1.6.3', '2.0.5']);
});

it('compares numerically instead of as strings', function () {
    expect(VersionRange::filterSatisfying(['1.9.0', '1.10.0'], '>=1.10'))
        ->toBe(['1.10.0']);
});

it('accepts v-prefixed versions as written in composer lock files', function () {
    expect(VersionRange::filterSatisfying(['v6.4.1', 'v7.1.0'], '^6.0'))
        ->toBe(['v6.4.1']);
});

it('skips versions it cannot parse instead of failing', function () {
    expect(VersionRange::filterSatisfying(['not-a-version', '1.0.0'], '^1.0'))
        ->toBe(['1.0.0']);
});

it('returns nothing for an invalid range', function () {
    expect(VersionRange::filterSatisfying(['1.0.0'], '>>> nope'))->toBe([]);
});

it('validates single versions', function (string $version, bool $isValid) {
    expect(VersionRange::isValidVersion($version))->toBe($isValid);
})->with([
    ['10.48.29', true],
    ['v6.4.1', true],
    ['1.2', true],
    ['', false],
    ['<1.2', false],
    ['1.0 || 2.0', false],
]);

it('splits a range into alternative groups of comparisons', function (string $range, ?array $expected) {
    expect(VersionRange::toComparisons($range))->toBe($expected);
})->with([
    'single bound' => ['<1.2.3', [[['<', '1.2.3']]]],
    'two bounds' => ['>=0 <v10.48.29', [[['>=', '0'], ['<', 'v10.48.29']]]],
    'alternatives' => ['>=1.0.0 <1.6.4 || >=2.0.0', [[['>=', '1.0.0'], ['<', '1.6.4']], [['>=', '2.0.0']]]],
    'comma and' => ['>= 10.0.0, < 10.48.29', [[['>=', '10.0.0'], ['<', '10.48.29']]]],
    'exact' => ['1.2.3', [[['=', '1.2.3']]]],
    'anything' => ['*', [[['>=', '0']]]],
    'unsupported' => ['^1.2', null],
]);

it('validates ranges', function (string $range, bool $isValid) {
    expect(VersionRange::isValid($range))->toBe($isValid);
})->with([
    ['>=10.0.0,<10.48.29', true],
    ['<1.6.4 || >=2.0.0 <2.1.1', true],
    ['^1.2', true],
    ['1.2.3 - 2.0.0', true],
    ['', false],
    ['>>> nope', false],
]);
