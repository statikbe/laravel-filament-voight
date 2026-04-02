<?php

use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Parsers\YarnLockParser;

it('parses packages from yarn.lock', function () {
    $content = <<<'YARN'
# yarn lockfile v1

lodash@^4.17.0:
  version "4.17.21"
  resolved "https://registry.yarnpkg.com/lodash/-/lodash-4.17.21.tgz"
  integrity sha512-abc123
  dependencies:
    lodash.merge "^4.6.2"

lodash.merge@^4.6.2:
  version "4.6.2"
  resolved "https://registry.yarnpkg.com/lodash.merge/-/lodash.merge-4.6.2.tgz"
  integrity sha512-def456

vitest@^1.0.0:
  version "1.0.0"
  resolved "https://registry.yarnpkg.com/vitest/-/vitest-1.0.0.tgz"
  integrity sha512-ghi789
YARN;

    $parser = new YarnLockParser;
    $packages = $parser->parse($content);

    expect($packages)->toHaveCount(3);

    $lodash = collect($packages)->firstWhere('name', 'lodash');
    expect($lodash['version'])->toBe('4.17.21')
        ->and($lodash['type'])->toBe(PackageType::Npm)
        ->and($lodash['require'])->toContain('lodash.merge');

    $lodashMerge = collect($packages)->firstWhere('name', 'lodash.merge');
    expect($lodashMerge['version'])->toBe('4.6.2')
        ->and($lodashMerge['require'])->toBeEmpty();

    $vitest = collect($packages)->firstWhere('name', 'vitest');
    expect($vitest['version'])->toBe('1.0.0');
});

it('determines is_dev and is_direct from package.json', function () {
    $yarnLock = <<<'YARN'
# yarn lockfile v1

lodash@^4.17.0:
  version "4.17.21"
  resolved "https://registry.yarnpkg.com/lodash/-/lodash-4.17.21.tgz"
  dependencies:
    lodash.merge "^4.6.2"

lodash.merge@^4.6.2:
  version "4.6.2"
  resolved "https://registry.yarnpkg.com/lodash.merge/-/lodash.merge-4.6.2.tgz"

vitest@^1.0.0:
  version "1.0.0"
  resolved "https://registry.yarnpkg.com/vitest/-/vitest-1.0.0.tgz"
YARN;

    $packageJson = json_encode([
        'dependencies' => [
            'lodash' => '^4.17.0',
        ],
        'devDependencies' => [
            'vitest' => '^1.0.0',
        ],
    ]);

    $parser = new YarnLockParser;
    $packages = $parser->parse($yarnLock, $packageJson);

    $lodash = collect($packages)->firstWhere('name', 'lodash');
    expect($lodash['is_direct'])->toBeTrue()
        ->and($lodash['is_dev'])->toBeFalse();

    $lodashMerge = collect($packages)->firstWhere('name', 'lodash.merge');
    expect($lodashMerge['is_direct'])->toBeFalse()
        ->and($lodashMerge['is_dev'])->toBeFalse();

    $vitest = collect($packages)->firstWhere('name', 'vitest');
    expect($vitest['is_direct'])->toBeTrue()
        ->and($vitest['is_dev'])->toBeTrue();
});

it('parses scoped packages', function () {
    $content = <<<'YARN'
# yarn lockfile v1

"@babel/core@^7.0.0":
  version "7.24.0"
  resolved "https://registry.yarnpkg.com/@babel/core/-/core-7.24.0.tgz"
  dependencies:
    "@babel/helper-module-transforms" "^7.23.0"

"@babel/helper-module-transforms@^7.23.0":
  version "7.23.3"
  resolved "https://registry.yarnpkg.com/@babel/helper-module-transforms/-/helper-module-transforms-7.23.3.tgz"
YARN;

    $parser = new YarnLockParser;
    $packages = $parser->parse($content);

    expect($packages)->toHaveCount(2);

    $core = collect($packages)->firstWhere('name', '@babel/core');
    expect($core['version'])->toBe('7.24.0')
        ->and($core['type'])->toBe(PackageType::Npm)
        ->and($core['require'])->toContain('@babel/helper-module-transforms');

    $helper = collect($packages)->firstWhere('name', '@babel/helper-module-transforms');
    expect($helper['version'])->toBe('7.23.3');
});

it('handles multiple version ranges for same package', function () {
    $content = <<<'YARN'
# yarn lockfile v1

"lodash@^4.17.0", "lodash@^4.17.21":
  version "4.17.21"
  resolved "https://registry.yarnpkg.com/lodash/-/lodash-4.17.21.tgz"
YARN;

    $parser = new YarnLockParser;
    $packages = $parser->parse($content);

    expect($packages)->toHaveCount(1)
        ->and($packages[0]['name'])->toBe('lodash')
        ->and($packages[0]['version'])->toBe('4.17.21');
});

it('returns empty array for empty content', function () {
    $parser = new YarnLockParser;
    $packages = $parser->parse('');

    expect($packages)->toBeEmpty();
});

it('returns empty array for comment-only content', function () {
    $parser = new YarnLockParser;
    $packages = $parser->parse("# yarn lockfile v1\n");

    expect($packages)->toBeEmpty();
});
