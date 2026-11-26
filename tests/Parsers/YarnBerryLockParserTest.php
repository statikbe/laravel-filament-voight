<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Parsers\UnsupportedLockfileException;
use Statikbe\FilamentVoight\Parsers\YarnBerryLockParser;

function parseBerryFixture(string $version = 'v8', bool $withManifest = true): array
{
    return byKey((new YarnBerryLockParser)->parse(
        lockfileFixture("yarn-berry/{$version}/yarn.lock"),
        $withManifest ? lockfileFixture("yarn-berry/{$version}/package.json") : null,
    ));
}

function berryKeys(array $packages, string $flag): array
{
    return collect($packages)->where($flag, true)->keys()->sort()->values()->all();
}

it('detects berry lockfiles only', function () {
    expect(YarnBerryLockParser::detects(lockfileFixture('yarn-berry/v8/yarn.lock')))->toBeTrue()
        ->and(YarnBerryLockParser::detects(lockfileFixture('yarn/yarn.lock')))->toBeFalse();
});

it('parses every hard-linked entry as a node keyed by its resolution', function () {
    $packages = parseBerryFixture();

    expect(array_keys($packages))->toEqualCanonicalizing([
        'ansi-regex@npm:5.0.1', 'ansi-styles@npm:4.3.0', 'chalk@npm:4.1.2', 'color-convert@npm:2.0.1',
        'color-name@npm:1.1.4', 'debug@npm:4.3.4', 'emoji-regex@npm:8.0.0', 'has-flag@npm:4.0.0',
        'is-fullwidth-code-point@npm:3.0.0', 'is-number@npm:7.0.0', 'lodash@npm:4.17.21', 'ms@npm:2.1.2',
        'picocolors@npm:1.0.0', 'string-width@npm:4.2.3', 'strip-ansi@npm:6.0.1', 'supports-color@npm:7.2.0',
        'typescript@npm:5.4.5',
    ])
        ->and($packages['chalk@npm:4.1.2']['name'])->toBe('chalk')
        ->and($packages['chalk@npm:4.1.2']['version'])->toBe('4.1.2')
        ->and($packages['chalk@npm:4.1.2']['type'])->toBe(PackageType::Npm);
});

it('names an aliased package after the package it resolves to', function () {
    expect(parseBerryFixture()['string-width@npm:4.2.3']['name'])->toBe('string-width');
});

it('resolves dependencies through descriptors, keeping the written range', function () {
    expect(parseBerryFixture()['chalk@npm:4.1.2']['dependencies'])->toBe([
        ['key' => 'ansi-styles@npm:4.3.0', 'constraint' => 'npm:^4.1.0', 'kind' => DependencyKind::Dependency],
        ['key' => 'supports-color@npm:7.2.0', 'constraint' => 'npm:^7.1.0', 'kind' => DependencyKind::Dependency],
    ]);
});

it('resolves yarn 2/3 ranges written without the npm protocol', function () {
    $packages = parseBerryFixture('v6', withManifest: false);

    expect(dependencyKeys($packages['chalk@npm:4.1.2']))->toBe(['ansi-styles@npm:4.3.0', 'supports-color@npm:7.2.0'])
        ->and($packages['color-convert@npm:2.0.1']['dependencies'][0]['constraint'])->toBe('~1.1.4');
});

it('resolves a patched dependency to the package it wraps', function (string $version) {
    $packages = parseBerryFixture($version, withManifest: false);

    expect($packages)->toHaveKey('typescript@npm:5.4.5')
        ->and($packages['typescript@npm:5.4.5']['is_direct'])->toBeTrue()
        ->and(collect($packages)->keys()->filter(fn (string $key): bool => str_contains($key, 'patch:')))->toBeEmpty();
})->with(['v8', 'v6']);

it('marks dependencies flagged optional in dependenciesMeta as optional', function () {
    $lockfile = <<<'YAML'
__metadata:
  version: 8

"vite@npm:^5.0.0":
  version: 5.0.0
  resolution: "vite@npm:5.0.0"
  dependencies:
    esbuild: "npm:^0.19.3"
    fsevents: "npm:~2.3.3"
  dependenciesMeta:
    fsevents:
      optional: true
  linkType: hard

"esbuild@npm:^0.19.3":
  version: 0.19.3
  resolution: "esbuild@npm:0.19.3"
  linkType: hard

"fsevents@npm:~2.3.3":
  version: 2.3.3
  resolution: "fsevents@npm:2.3.3"
  linkType: hard
YAML;

    $vite = byKey((new YarnBerryLockParser)->parse($lockfile))['vite@npm:5.0.0'];

    expect(collect($vite['dependencies'])->pluck('kind', 'key')->all())->toBe([
        'esbuild@npm:0.19.3' => DependencyKind::Dependency,
        'fsevents@npm:2.3.3' => DependencyKind::Optional,
    ]);
});

it('drops unresolvable dependencies and does not add peer edges', function () {
    expect(parseBerryFixture()['debug@npm:4.3.4']['dependencies'])->toBe([
        ['key' => 'ms@npm:2.1.2', 'constraint' => 'npm:2.1.2', 'kind' => DependencyKind::Dependency],
    ]);
});

it('marks what the root and member workspaces declare as direct', function () {
    expect(berryKeys(parseBerryFixture(), 'is_direct'))->toBe([
        'chalk@npm:4.1.2', 'debug@npm:4.3.4', 'is-number@npm:7.0.0', 'lodash@npm:4.17.21',
        'picocolors@npm:1.0.0', 'string-width@npm:4.2.3', 'strip-ansi@npm:6.0.1', 'typescript@npm:5.4.5',
    ]);
});

it('marks root dev dependencies and what only they reach as dev', function () {
    // ansi-regex is also reached by string-width (production); picocolors is a
    // member workspace's dev dependency, which the lockfile cannot tell apart.
    expect(berryKeys(parseBerryFixture(), 'is_dev'))->toBe([
        'debug@npm:4.3.4', 'ms@npm:2.1.2', 'strip-ansi@npm:6.0.1', 'typescript@npm:5.4.5',
    ]);
});

it('treats every dependency as production without package.json', function () {
    $packages = parseBerryFixture(withManifest: false);

    expect(berryKeys($packages, 'is_dev'))->toBeEmpty()
        ->and(berryKeys($packages, 'is_direct'))->toContain('debug@npm:4.3.4', 'lodash@npm:4.17.21');
});

it('falls back to unrequired entries as direct without workspace entries', function () {
    $lockfile = <<<'YAML'
__metadata:
  version: 8

"debug@npm:^4.3.4":
  version: 4.3.4
  resolution: "debug@npm:4.3.4"
  dependencies:
    ms: "npm:2.1.2"
  linkType: hard

"ms@npm:2.1.2":
  version: 2.1.2
  resolution: "ms@npm:2.1.2"
  linkType: hard
YAML;

    expect(berryKeys(byKey((new YarnBerryLockParser)->parse($lockfile)), 'is_direct'))->toBe(['debug@npm:4.3.4']);
});

it('parses CRLF line endings the same as LF', function () {
    $lf = lockfileFixture('yarn-berry/v8/yarn.lock');
    $manifest = lockfileFixture('yarn-berry/v8/package.json');
    $parser = new YarnBerryLockParser;

    expect($parser->parse(str_replace("\n", "\r\n", $lf), $manifest))->toEqual($parser->parse($lf, $manifest));
});

it('takes the version from the resolution when yaml reads it as a number', function () {
    $lockfile = <<<'YAML'
__metadata:
  version: 8

"legacy@npm:^1.10":
  version: 1.10
  resolution: "legacy@npm:1.10"
  linkType: hard
YAML;

    expect((new YarnBerryLockParser)->parse($lockfile)[0]['version'])->toBe('1.10');
});

it('rejects invalid yaml as unreadable', function (string $content) {
    expect(fn () => (new YarnBerryLockParser)->parse($content))
        ->toThrow(fn (UnsupportedLockfileException $e) => expect($e->warning)->toBe(SyncWarning::LockfileUnreadable));
})->with([
    'broken yaml' => "__metadata:\n  version: 8\n\"chalk@npm:^4.1.2:\n  version: [4.1.2\n",
    'not a map' => '__metadata',
]);
