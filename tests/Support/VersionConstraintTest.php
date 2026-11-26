<?php

use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Support\VersionConstraint;

function matchesInSql(VersionConstraint $constraint, string $version): bool
{
    $installed = EnvironmentPackage::factory()->create(['version' => $version]);

    return $constraint->apply(EnvironmentPackage::query()->whereKey($installed->getKey()), 'version_sort')->exists();
}

it('matches versions against every comparison', function (string $constraint, string $version, bool $matches) {
    expect(matchesInSql(VersionConstraint::parse($constraint), $version))->toBe($matches);
})->with([
    'below' => ['<4.17.21', '4.17.15', true],
    'below, equal' => ['<4.17.21', '4.17.21', false],
    'at most' => ['<=4.17.21', '4.17.21', true],
    'above' => ['>2.0', '2.0.1', true],
    'at least' => ['>=2.0', '2.0', true],
    'equal' => ['=1.2.3', '1.2.3', true],
    'double equal' => ['==1.2.3', '1.2.4', false],
    'bare version is equality' => ['1.2.3', '1.2.3', true],
    'range inside' => ['>=7.0 <7.4', '7.2.1', true],
    'range lower bound' => ['>=7.0 <7.4', '6.9.9', false],
    'range upper bound' => ['>=7.0 <7.4', '7.4.0', false],
    'comma separated range' => ['>=7.0, <7.4', '7.3', true],
    'space after operator' => ['>= 7.0 < 7.4', '7.1', true],
    'leading v on the constraint' => ['>=v2.0', '2.1', true],
    'leading v on the version' => ['<3.0', 'v2.9.9', true],
    'numeric minor order' => ['<7.10', '7.9.0', true],
    'pre-release before release' => ['<2.0.0', '2.0.0-beta1', true],
]);

it('rejects malformed constraints', function (string $constraint) {
    VersionConstraint::parse($constraint);
})->with(['', '   ', '~>4', '^1.2', 'latest', '>=', '<4.0 or >5.0'])->throws(InvalidArgumentException::class);

it('builds a constraint from optional lower and upper bounds', function () {
    $constraint = VersionConstraint::between(['>=', 'v2.0'], ['<', '3.0']);

    expect(matchesInSql($constraint, '2.5'))->toBeTrue()
        ->and(matchesInSql($constraint, '3.0'))->toBeFalse()
        ->and(matchesInSql(VersionConstraint::between(null, ['<', '3.0']), '1.0'))->toBeTrue();
});

/**
 * Versions matched in SQL by the constraint, against installed packages keyed on save.
 *
 * @return array<int, string>
 */
function versionsInRange(VersionConstraint $constraint): array
{
    foreach (['1.0', '1.0.0', '2.0.0-beta', '2.0.0', '7.3.9', '7.4.0', '7.4.x-dev', '7.5.0', '8.0.0-beta', 'dev-main'] as $version) {
        EnvironmentPackage::factory()->create(['version' => $version]);
    }

    return $constraint->apply(EnvironmentPackage::query(), 'version_sort')
        ->orderBy('version_sort')
        ->pluck('version')
        ->all();
}

it('applies a range in sql', function (string $constraint, array $versions) {
    expect(versionsInRange(VersionConstraint::parse($constraint)))->toEqualCanonicalizing($versions);
})->with([
    'between' => ['>=7.0 <7.4', ['7.3.9']],
    'below includes pre-releases' => ['<2.0.0', ['1.0', '1.0.0', '2.0.0-beta']],
    'missing parts are zero' => ['=1.0', ['1.0', '1.0.0']],
    'branch alias inside its minor' => ['>=7.4 <7.5', ['7.4.0', '7.4.x-dev']],
]);

it('applies short bounds as zero-filled versions', function () {
    expect(versionsInRange(VersionConstraint::parse('<8')))->toContain('8.0.0-beta', '7.5.0')
        ->and(versionsInRange(VersionConstraint::parse('>=7.4')))->toContain('7.4.0');
});

it('never matches versions without a key', function () {
    expect(versionsInRange(VersionConstraint::parse('>=0')))->not->toContain('dev-main');
});

it('rejects bounds that are not versions', function () {
    expect(fn () => VersionConstraint::parse('<dev-main'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => VersionConstraint::between(['>', 'dev-main'], null))->toThrow(InvalidArgumentException::class);
});
