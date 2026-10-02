<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Parsers\ComposerLockParser;

function parseComposerFixture(bool $withManifest = true): array
{
    return byKey((new ComposerLockParser)->parse(
        lockfileFixture('composer/composer.lock'),
        $withManifest ? lockfileFixture('composer/composer.json') : null,
    ));
}

it('parses every locked package as a node keyed by its lowercased name', function () {
    $packages = parseComposerFixture();

    expect(array_keys($packages))->toEqualCanonicalizing([
        'laravel/framework', 'spatie/laravel-package-tools', 'nesbot/carbon', 'monolog/monolog',
        'psr/log', 'symfony/polyfill-mbstring', 'pestphp/pest', 'nunomaduro/collision',
    ])
        ->and($packages['laravel/framework']['name'])->toBe('laravel/framework')
        ->and($packages['laravel/framework']['version'])->toBe('12.69.3')
        ->and($packages['laravel/framework']['type'])->toBe(PackageType::Composer);
});

it('resolves requirements to edges with their declared constraint, skipping platform requirements', function () {
    $framework = parseComposerFixture()['laravel/framework'];

    expect(dependencyKeys($framework))->toBe(['monolog/monolog', 'nesbot/carbon', 'psr/log'])
        ->and(collect($framework['dependencies'])->firstWhere('key', 'monolog/monolog'))
        ->toBe(['key' => 'monolog/monolog', 'constraint' => '^3.0', 'kind' => DependencyKind::Dependency]);
});

it('resolves a requirement on a replaced package to the replacing package', function () {
    $packageTools = parseComposerFixture()['spatie/laravel-package-tools'];

    expect($packageTools['dependencies'])->toBe([
        ['key' => 'laravel/framework', 'constraint' => '^10.0|^11.0|^12.0|^13.0', 'kind' => DependencyKind::Dependency],
    ]);
});

it('resolves dev packages through their require section', function () {
    expect(dependencyKeys(parseComposerFixture()['pestphp/pest']))->toBe(['nunomaduro/collision']);
});

it('marks the packages required by composer.json as direct', function () {
    $direct = collect(parseComposerFixture())->where('is_direct', true)->keys()->sort()->values()->all();

    expect($direct)->toBe(['laravel/framework', 'pestphp/pest', 'spatie/laravel-package-tools']);
});

it('falls back to packages nothing else requires when composer.json is missing', function () {
    $direct = collect(parseComposerFixture(withManifest: false))->where('is_direct', true)->keys()->sort()->values()->all();

    // laravel/framework is required by spatie/laravel-package-tools, so the fallback misses it.
    expect($direct)->toBe(['pestphp/pest', 'spatie/laravel-package-tools']);
});

it('marks packages-dev entries as dev', function () {
    $dev = collect(parseComposerFixture())->where('is_dev', true)->keys()->sort()->values()->all();

    expect($dev)->toBe(['nunomaduro/collision', 'pestphp/pest']);
});

it('links a requirement on a virtual package to every provider', function () {
    $lock = json_encode(['packages' => [
        ['name' => 'app/logger-user', 'version' => '1.0.0', 'require' => ['psr/log-implementation' => '^3.0']],
        ['name' => 'monolog/monolog', 'version' => '3.12.1', 'provide' => ['psr/log-implementation' => '3.0.0']],
        ['name' => 'laravel/framework', 'version' => '12.0.0', 'provide' => ['psr/log-implementation' => '3.0']],
    ]]);

    $user = byKey((new ComposerLockParser)->parse($lock))['app/logger-user'];

    expect(dependencyKeys($user))->toBe(['laravel/framework', 'monolog/monolog']);
});

it('prefers a real package over one that replaces or provides its name', function () {
    $lock = json_encode(['packages' => [
        ['name' => 'app/consumer', 'version' => '1.0.0', 'require' => ['psr/log' => '^3.0']],
        ['name' => 'psr/log', 'version' => '3.0.2'],
        ['name' => 'acme/log-bundle', 'version' => '1.0.0', 'replace' => ['psr/log' => '*']],
    ]]);

    expect(dependencyKeys(byKey((new ComposerLockParser)->parse($lock))['app/consumer']))->toBe(['psr/log']);
});

it('matches package names case-insensitively', function () {
    $lock = json_encode(['packages' => [
        ['name' => 'app/consumer', 'version' => '1.0.0', 'require' => ['Psr/Log' => '^3.0']],
        ['name' => 'psr/log', 'version' => '3.0.2'],
    ]]);
    $manifest = json_encode(['require' => ['App/Consumer' => '^1.0']]);

    $packages = byKey((new ComposerLockParser)->parse($lock, $manifest));

    expect(dependencyKeys($packages['app/consumer']))->toBe(['psr/log'])
        ->and($packages['app/consumer']['is_direct'])->toBeTrue();
});

it('marks the replacing package direct when composer.json requires a replaced name', function () {
    $lock = json_encode(['packages' => [
        ['name' => 'laravel/framework', 'version' => '12.0.0', 'replace' => ['illuminate/support' => 'self.version']],
    ]]);
    $manifest = json_encode(['require' => ['illuminate/support' => '^12.0']]);

    expect(byKey((new ComposerLockParser)->parse($lock, $manifest))['laravel/framework']['is_direct'])->toBeTrue();
});

it('drops requirements that are not in the lockfile', function () {
    $lock = json_encode(['packages' => [
        ['name' => 'app/consumer', 'version' => '1.0.0', 'require' => ['missing/package' => '^1.0']],
    ]]);

    expect(byKey((new ComposerLockParser)->parse($lock))['app/consumer']['dependencies'])->toBe([]);
});

it('strips the v prefix from versions', function () {
    $lock = json_encode(['packages' => [['name' => 'foo/bar', 'version' => 'v1.2.3']]]);

    expect((new ComposerLockParser)->parse($lock)[0]['version'])->toBe('1.2.3');
});

it('returns an empty array for invalid json', function () {
    expect((new ComposerLockParser)->parse('not json'))->toBeEmpty();
});
