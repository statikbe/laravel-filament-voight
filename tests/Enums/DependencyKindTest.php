<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;

it('exposes the dependency kinds with string values', function () {
    expect(DependencyKind::Dependency->value)->toBe('dependency')
        ->and(DependencyKind::Optional->value)->toBe('optional')
        ->and(DependencyKind::Peer->value)->toBe('peer');
});
