<?php

use Statikbe\FilamentVoight\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Raw contents of a lockfile fixture under tests/Fixtures/lockfiles.
 */
function lockfileFixture(string $path): string
{
    return file_get_contents(__DIR__ . '/Fixtures/lockfiles/' . $path);
}

/**
 * Index parsed packages by their key.
 *
 * @param  array<int, array<string, mixed>>  $packages
 * @return array<string, array<string, mixed>>
 */
function byKey(array $packages): array
{
    return collect($packages)->keyBy('key')->all();
}

/**
 * The keys a parsed package depends on, sorted.
 *
 * @param  array<string, mixed>  $package
 * @return array<int, string>
 */
function dependencyKeys(array $package): array
{
    return collect($package['dependencies'])->pluck('key')->sort()->values()->all();
}
