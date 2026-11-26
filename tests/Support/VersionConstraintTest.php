<?php

use Statikbe\FilamentVoight\Support\VersionConstraint;

it('matches versions against every comparison', function (string $constraint, string $version, bool $matches) {
    expect(VersionConstraint::parse($constraint)->matches($version))->toBe($matches);
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

    expect($constraint->matches('2.5'))->toBeTrue()
        ->and($constraint->matches('3.0'))->toBeFalse()
        ->and(VersionConstraint::between(null, ['<', '3.0'])->matches('1.0'))->toBeTrue();
});
