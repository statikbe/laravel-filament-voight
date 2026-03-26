<?php

use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Parsers\ComposerLockParser;

it('parses packages from composer.lock', function () {
    $content = json_encode([
        'packages' => [
            [
                'name' => 'laravel/framework',
                'version' => 'v11.0.0',
                'require' => [
                    'php' => '^8.2',
                    'illuminate/support' => '^11.0',
                ],
            ],
            [
                'name' => 'illuminate/support',
                'version' => 'v11.0.0',
                'require' => [],
            ],
        ],
        'packages-dev' => [
            [
                'name' => 'pestphp/pest',
                'version' => 'v3.0.0',
                'require' => [],
            ],
        ],
    ]);

    $parser = new ComposerLockParser;
    $packages = $parser->parse($content);

    expect($packages)->toHaveCount(3);

    $laravel = collect($packages)->firstWhere('name', 'laravel/framework');
    expect($laravel['version'])->toBe('11.0.0')
        ->and($laravel['type'])->toBe(PackageType::Composer)
        ->and($laravel['is_dev'])->toBeFalse()
        ->and($laravel['require'])->toContain('illuminate/support');

    $pest = collect($packages)->firstWhere('name', 'pestphp/pest');
    expect($pest['is_dev'])->toBeTrue();
});

it('strips v prefix from versions', function () {
    $content = json_encode([
        'packages' => [
            ['name' => 'foo/bar', 'version' => 'v1.2.3', 'require' => []],
        ],
        'packages-dev' => [],
    ]);

    $parser = new ComposerLockParser;
    $packages = $parser->parse($content);

    expect($packages[0]['version'])->toBe('1.2.3');
});

it('returns empty array for invalid json', function () {
    $parser = new ComposerLockParser;
    $packages = $parser->parse('not json');

    expect($packages)->toBeEmpty();
});
