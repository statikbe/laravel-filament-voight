<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Parsers\PackageLockParser;
use Statikbe\FilamentVoight\Parsers\UnsupportedLockfileException;

function parseNpmFixture(): array
{
    return byKey((new PackageLockParser)->parse(lockfileFixture('npm/package-lock.json')));
}

/**
 * @return array<string, mixed>
 */
function edge(array $package, string $key): ?array
{
    return collect($package['dependencies'])->firstWhere('key', $key);
}

it('includes nested installs as nodes and skips workspace links and members', function () {
    expect(array_keys(parseNpmFixture()))->toEqualCanonicalizing([
        'node_modules/@isaacs/cliui',
        'node_modules/@vue/runtime-core',
        'node_modules/@vue/shared',
        'node_modules/debug',
        'node_modules/esbuild',
        'node_modules/express',
        'node_modules/express/node_modules/debug',
        'node_modules/express/node_modules/ms',
        'node_modules/fsevents',
        'node_modules/lodash',
        'node_modules/ms',
        'node_modules/send',
        'node_modules/send/node_modules/debug',
        'node_modules/send/node_modules/debug/node_modules/ms',
        'node_modules/send/node_modules/ms',
        'node_modules/string-width-cjs',
        'node_modules/vite',
        'node_modules/vue',
        'packages/ui/node_modules/lodash',
    ]);
});

it('names nodes after their path segment, keeping scopes, or the aliased package name', function () {
    $packages = parseNpmFixture();

    expect($packages['node_modules/send/node_modules/debug/node_modules/ms']['name'])->toBe('ms')
        ->and($packages['node_modules/@vue/shared']['name'])->toBe('@vue/shared')
        ->and($packages['node_modules/string-width-cjs']['name'])->toBe('string-width')
        ->and($packages['node_modules/send/node_modules/debug/node_modules/ms']['version'])->toBe('2.0.0')
        ->and($packages['node_modules/vue']['type'])->toBe(PackageType::Npm);
});

it('keeps two versions of a package and the same version at two nested paths', function () {
    $debugVersions = collect(parseNpmFixture())->where('name', 'debug')->pluck('version', 'key')->all();
    $msVersions = collect(parseNpmFixture())->where('name', 'ms')->pluck('version')->sort()->values()->all();

    expect($debugVersions)->toEqualCanonicalizing([
        'node_modules/debug' => '4.3.4',
        'node_modules/express/node_modules/debug' => '2.6.9',
        'node_modules/send/node_modules/debug' => '2.6.9',
    ])->and($msVersions)->toBe(['2.0.0', '2.0.0', '2.1.2', '2.1.3']);
});

it('resolves dependencies with the node resolution walk-up', function () {
    $packages = parseNpmFixture();

    expect(dependencyKeys($packages['node_modules/express']))
        ->toBe(['node_modules/express/node_modules/debug', 'node_modules/send'])
        ->and(dependencyKeys($packages['node_modules/express/node_modules/debug']))
        ->toBe(['node_modules/express/node_modules/ms'])
        ->and(dependencyKeys($packages['node_modules/send/node_modules/debug']))
        ->toBe(['node_modules/send/node_modules/debug/node_modules/ms'])
        ->and(dependencyKeys($packages['node_modules/send']))
        ->toBe(['node_modules/lodash', 'node_modules/send/node_modules/debug', 'node_modules/send/node_modules/ms']);
});

it('records the declared range and the kind of every edge', function () {
    $packages = parseNpmFixture();

    expect(edge($packages['node_modules/send'], 'node_modules/lodash'))
        ->toBe(['key' => 'node_modules/lodash', 'constraint' => '^4.17.0', 'kind' => DependencyKind::Dependency])
        ->and(edge($packages['node_modules/vite'], 'node_modules/fsevents')['kind'])->toBe(DependencyKind::Optional)
        ->and(edge($packages['node_modules/@vue/runtime-core'], 'node_modules/vue')['kind'])->toBe(DependencyKind::Peer)
        ->and(edge($packages['node_modules/@isaacs/cliui'], 'node_modules/string-width-cjs')['constraint'])->toBe('npm:string-width@^4.2.0');
});

it('keeps one edge with the strongest kind when a name is listed in several dependency maps', function () {
    $runtimeCore = parseNpmFixture()['node_modules/@vue/runtime-core'];

    expect(collect($runtimeCore['dependencies'])->where('key', 'node_modules/@vue/shared')->values()->all())
        ->toBe([['key' => 'node_modules/@vue/shared', 'constraint' => '3.4.0', 'kind' => DependencyKind::Dependency]]);
});

it('keeps the edges of a dependency cycle', function () {
    $packages = parseNpmFixture();

    expect(dependencyKeys($packages['node_modules/vue']))->toBe(['node_modules/@vue/runtime-core'])
        ->and(dependencyKeys($packages['node_modules/@vue/runtime-core']))->toContain('node_modules/vue');
});

it('drops unresolvable peer dependencies', function () {
    expect(dependencyKeys(parseNpmFixture()['node_modules/vite']))
        ->toBe(['node_modules/@isaacs/cliui', 'node_modules/esbuild', 'node_modules/fsevents']);
});

it('marks what the root and workspace members resolve their declared dependencies to as direct', function () {
    $direct = collect(parseNpmFixture())->where('is_direct', true)->keys()->sort()->values()->all();

    expect($direct)->toBe([
        'node_modules/debug',
        'node_modules/express',
        'node_modules/fsevents',
        'node_modules/vite',
        'node_modules/vue',
        'packages/ui/node_modules/lodash',
    ]);
});

it('marks dev and dev-optional entries as dev', function () {
    $dev = collect(parseNpmFixture())->where('is_dev', true)->keys()->sort()->values()->all();

    expect($dev)->toBe([
        'node_modules/@isaacs/cliui',
        'node_modules/esbuild',
        'node_modules/fsevents',
        'node_modules/string-width-cjs',
        'node_modules/vite',
    ]);
});

it('ignores the legacy dependencies list of a lockfile v2', function () {
    $lock = json_decode(lockfileFixture('npm/package-lock.json'), true);
    $lock['lockfileVersion'] = 2;
    $lock['dependencies'] = ['ms' => ['version' => '2.1.2'], 'esbuild' => ['version' => '0.19.3', 'dev' => true]];

    $direct = collect((new PackageLockParser)->parse(json_encode($lock)))->where('is_direct', true)->pluck('key')->sort()->values()->all();

    expect($direct)->not->toContain('node_modules/ms')
        ->and($direct)->not->toContain('node_modules/esbuild')
        ->and($direct)->toContain('node_modules/express');
});

it('returns empty array for invalid json', function () {
    expect((new PackageLockParser)->parse('not json'))->toBeEmpty();
});

it('rejects an npm lockfile v1 as unsupported', function () {
    $lockfileV1 = json_encode([
        'lockfileVersion' => 1,
        'dependencies' => ['lodash' => ['version' => '4.17.21']],
    ]);

    expect(fn () => (new PackageLockParser)->parse($lockfileV1))
        ->toThrow(fn (UnsupportedLockfileException $e) => expect($e->warning)->toBe(SyncWarning::NpmLockfileV1Unsupported));
});
