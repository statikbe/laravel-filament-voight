<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentVoight\Support\VersionKey;

/**
 * @return array<int, string>
 */
function ascendingVersions(): array
{
    return [
        '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.10', '1.0.0-rc.1',
        '1.0.0-RC2', '1.0.0-RC10', '1.0.0', '1.0.0-p1', '1.0.0.1', '1.0.1', '1.0.30001720', '1.0.30001826',
        '1.0.x-dev', '1.1.0', '2.0.0',
    ];
}

it('orders versions by semver precedence with composer patches after the release', function () {
    $versions = ascendingVersions();
    $shuffled = $versions;
    shuffle($shuffled);

    usort($shuffled, fn (string $a, string $b): int => strcmp(VersionKey::for($a), VersionKey::for($b)));

    expect($shuffled)->toBe($versions)
        ->and(array_filter(array_map(VersionKey::for(...), $versions), is_null(...)))->toBe([]);
});

it('treats missing parts, a v prefix and build metadata as equal', function () {
    expect(VersionKey::for('1.0'))
        ->toBe(VersionKey::for('1.0.0'))
        ->toBe(VersionKey::for('v1.0.0.0'))
        ->toBe(VersionKey::for('1.0.0+build.5'));
});

it('normalises case and prefix', function () {
    expect(VersionKey::for('V2.0.0-RC1'))->toBe(VersionKey::for('2.0.0-rc1'));
});

it('reads composer suffixes without a dash', function () {
    expect(VersionKey::for('1.0.0beta2'))->toBe(VersionKey::for('1.0.0-beta2'))
        ->and(VersionKey::for('1.0.0-rc10'))->toBe(VersionKey::for('1.0.0-rc.10'));
});

it('keys a composer branch alias as the newest dev build of its line', function () {
    expect(VersionKey::for('7.4.x-dev'))->toBeGreaterThan(VersionKey::for('7.4.99'))
        ->and(VersionKey::for('7.4.x-dev'))->toBeLessThan(VersionKey::for('7.5.0-alpha'))
        ->and(VersionKey::for('2.x-dev'))->toBeGreaterThan(VersionKey::for('2.99.99'))
        ->and(VersionKey::for('2.x-dev'))->toBeLessThan(VersionKey::for('3.0.0-alpha'));
});

it('returns null for versions that cannot be compared', function (string $version) {
    expect(VersionKey::for($version))->toBeNull();
})->with([
    'dev branch' => 'dev-main',
    'feature branch' => 'dev-feature/translatable-collection',
    'five parts' => '1.2.3.4.5',
    'eleven digit part' => '1.12345678901.0',
    'git locator' => 'github:jonschlinkert/is-even#585f800',
    'other url' => 'https://example.com/archive.zip',
    'non-ascii pre-release' => '1.0.0-béta',
    'empty' => '',
]);

it('reads the version from a registry tarball url', function () {
    expect(VersionKey::for('https://registry.npmjs.org/is-number/-/is-number-7.0.0.tgz'))->toBe(VersionKey::for('7.0.0'))
        ->and(VersionKey::for('https://registry.npmjs.org/@babel/core/-/core-7.25.9.tgz'))->toBe(VersionKey::for('7.25.9'));
});

it('drops distro suffixes from php versions', function () {
    expect(VersionKey::forPhp('8.3.12-1+ubuntu22.04.1+deb.sury.org+1'))->toBe(VersionKey::for('8.3.12'));
});

it('orders keys the same in the database as in php', function () {
    Schema::create('version_key_probe', fn (Blueprint $table) => $table->string('k', 191));
    $keys = array_map(VersionKey::for(...), ascendingVersions());
    $shuffled = $keys;
    shuffle($shuffled);

    DB::table('version_key_probe')->insert(array_map(fn (string $key): array => ['k' => $key], $shuffled));

    expect(DB::table('version_key_probe')->orderBy('k')->pluck('k')->all())->toBe($keys);
});
