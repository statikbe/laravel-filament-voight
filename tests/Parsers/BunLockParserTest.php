<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Parsers\BunLockParser;
use Statikbe\FilamentVoight\Parsers\UnsupportedLockfileException;

function parseBun(string $content): array
{
    return byKey((new BunLockParser)->parse($content));
}

function parseBunFixture(): array
{
    return parseBun(lockfileFixture('bun/bun.lock'));
}

function bunKeys(array $packages, string $flag): array
{
    return collect($packages)->where($flag, true)->keys()->sort()->values()->all();
}

/**
 * Lockfile without workspaces: `@scope/a` → nested `@scope/a/b` → `c`. The decoy
 * `@scope/c` catches a lookup that walks up by slash instead of by package name.
 */
const SCOPED_BUN_LOCK = <<<'JSONC'
{
  "lockfileVersion": 1,
  "packages": {
    "@scope/a": ["@scope/a@1.0.0", "", { "dependencies": { "b": "1" } }, "sha512-a"],
    "@scope/a/b": ["b@1.0.0", "", { "dependencies": { "c": "1" } }, "sha512-b"],
    "@scope/c": ["@scope/c@1.0.0", "", {}, "sha512-sc"],
    "c": ["c@1.0.0", "", {}, "sha512-c"],
  }
}
JSONC;

it('parses every installed package as a node keyed by its install path', function () {
    $packages = parseBunFixture();

    expect($packages)->toHaveCount(29)
        ->not->toHaveKey('lib')
        ->and($packages['debug/ms'])->toMatchArray(['name' => 'ms', 'version' => '2.1.3', 'type' => PackageType::Npm])
        ->and($packages['string-width-cjs'])->toMatchArray(['name' => 'string-width', 'version' => '4.2.3'])
        ->and($packages['@babel/helper-string-parser']['name'])->toBe('@babel/helper-string-parser')
        ->and($packages['is-even']['version'])->toBe('github:jonschlinkert/is-even#585f800');
});

it('resolves dependencies to the nearest installed copy', function () {
    $packages = parseBunFixture();

    expect(dependencyKeys($packages['debug']))->toBe(['debug/ms'])
        ->and(dependencyKeys($packages['is-even']))->toBe(['is-even/is-odd'])
        ->and(dependencyKeys($packages['is-even/is-odd']))->toBe(['is-even/is-odd/is-number'])
        ->and(dependencyKeys($packages['is-even/is-odd/is-number']))->toBe(['kind-of'])
        ->and($packages['chalk']['dependencies'][0])->toBe(['key' => 'ansi-styles', 'constraint' => '^4.1.0', 'kind' => DependencyKind::Dependency]);
});

it('records optional dependencies and drops uninstalled optional peers', function () {
    $packages = parseBunFixture();

    expect($packages['playwright']['dependencies'])->toBe([
        ['key' => 'playwright-core', 'constraint' => '1.48.2', 'kind' => DependencyKind::Dependency],
        ['key' => 'fsevents', 'constraint' => '2.3.2', 'kind' => DependencyKind::Optional],
    ])->and($packages['ws']['dependencies'])->toBe([]);
});

it('marks workspace dependencies direct and dev-only ones dev', function () {
    $packages = parseBunFixture();

    // picocolors: dev in the root, production in lib. supports-color: root dev, but required by production chalk.
    expect(bunKeys($packages, 'is_direct'))->toBe([
        '@babel/helper-string-parser', 'chalk', 'debug', 'is-even', 'is-number', 'is-odd', 'lib/chalk',
        'ms', 'picocolors', 'playwright', 'string-width-cjs', 'supports-color', 'ws',
    ])->and(bunKeys($packages, 'is_dev'))->toBe(['debug', 'debug/ms', 'is-odd', 'is-odd/is-number']);
});

it('walks up whole scoped names when resolving', function () {
    $packages = parseBun(SCOPED_BUN_LOCK);

    expect(dependencyKeys($packages['@scope/a']))->toBe(['@scope/a/b'])
        ->and(dependencyKeys($packages['@scope/a/b']))->toBe(['c']);
});

it('falls back to unrequired packages being direct without workspaces', function () {
    expect(bunKeys(parseBun(SCOPED_BUN_LOCK), 'is_direct'))->toBe(['@scope/a', '@scope/c']);
});

it('records resolvable peer dependencies', function () {
    $packages = parseBun(<<<'JSONC'
        {
          "packages": {
            "plugin": ["plugin@1.0.0", "", { "peerDependencies": { "host": "^2" } }, "sha512-p"],
            "host": ["host@2.0.0", "", {}, "sha512-h"],
          },
        }
        JSONC);

    expect($packages['plugin']['dependencies'])->toBe([
        ['key' => 'host', 'constraint' => '^2', 'kind' => DependencyKind::Peer],
    ]);
});

it('skips link and file entries', function () {
    $packages = parseBun(<<<'JSONC'
        {
          "packages": {
            "local": ["local@link:local"],
            "dir": ["dir@file:../dir", {}],
            "c": ["c@1.0.0", "", {}, "sha512-c"],
          },
        }
        JSONC);

    expect(array_keys($packages))->toBe(['c']);
});

it('keeps commas inside strings', function () {
    $packages = parseBun(<<<'JSONC'
        {
          "packages": {
            "a": ["a@1.0.0", "", { "dependencies": { "c": "1.0.0,}" } }, "sha512-a",],
            "c": ["c@1.0.0", "", {}, "sha512-c"],
          },
        }
        JSONC);

    expect($packages['a']['dependencies'][0]['constraint'])->toBe('1.0.0,}');
});

it('rejects unreadable content', function (string $content) {
    expect(fn () => (new BunLockParser)->parse($content))->toThrow(
        fn (UnsupportedLockfileException $e) => expect($e->warning)->toBe(SyncWarning::LockfileUnreadable),
    );
})->with(['not json' => 'not json', 'no packages' => '{"lockfileVersion": 1}', 'a list' => '[]']);
