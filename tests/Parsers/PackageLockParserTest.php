<?php

use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Parsers\PackageLockParser;

it('parses packages from package-lock.json', function () {
    $content = json_encode([
        'packages' => [
            '' => [
                'name' => 'my-project',
                'version' => '1.0.0',
            ],
            'node_modules/lodash' => [
                'version' => '4.17.21',
                'dev' => false,
                'dependencies' => [
                    'lodash.merge' => '^4.6.2',
                ],
            ],
            'node_modules/lodash.merge' => [
                'version' => '4.6.2',
                'dev' => false,
            ],
            'node_modules/vitest' => [
                'version' => '1.0.0',
                'dev' => true,
            ],
        ],
        'dependencies' => [
            'lodash' => '^4.17.0',
        ],
        'devDependencies' => [
            'vitest' => '^1.0.0',
        ],
    ]);

    $parser = new PackageLockParser;
    $packages = $parser->parse($content);

    expect($packages)->toHaveCount(3);

    $lodash = collect($packages)->firstWhere('name', 'lodash');
    expect($lodash['version'])->toBe('4.17.21')
        ->and($lodash['type'])->toBe(PackageType::Npm)
        ->and($lodash['is_direct'])->toBeTrue()
        ->and($lodash['is_dev'])->toBeFalse()
        ->and($lodash['require'])->toContain('lodash.merge');

    $lodashMerge = collect($packages)->firstWhere('name', 'lodash.merge');
    expect($lodashMerge['is_direct'])->toBeFalse();

    $vitest = collect($packages)->firstWhere('name', 'vitest');
    expect($vitest['is_direct'])->toBeTrue()
        ->and($vitest['is_dev'])->toBeTrue();
});

it('skips nested node_modules', function () {
    $content = json_encode([
        'packages' => [
            '' => ['name' => 'root'],
            'node_modules/foo' => ['version' => '1.0.0'],
            'node_modules/foo/node_modules/bar' => ['version' => '2.0.0'],
        ],
    ]);

    $parser = new PackageLockParser;
    $packages = $parser->parse($content);

    expect($packages)->toHaveCount(1)
        ->and($packages[0]['name'])->toBe('foo');
});

it('returns empty array for invalid json', function () {
    $parser = new PackageLockParser;
    $packages = $parser->parse('not json');

    expect($packages)->toBeEmpty();
});
