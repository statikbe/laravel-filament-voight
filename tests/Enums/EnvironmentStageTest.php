<?php

use Filament\Support\Colors\Color;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;

it('derives the stage from the environment name', function (string $name, EnvironmentStage $stage) {
    expect(EnvironmentStage::fromName($name))->toBe($stage);
})->with([
    ['production', EnvironmentStage::Production],
    ['Prod', EnvironmentStage::Production],
    ['prd', EnvironmentStage::Production],
    ['live', EnvironmentStage::Production],
    ['live2', EnvironmentStage::Production],
    ['acceptance', EnvironmentStage::Acceptance],
    ['ACC', EnvironmentStage::Acceptance],
    ['uat', EnvironmentStage::Acceptance],
    ['staging', EnvironmentStage::Staging],
    ['test', EnvironmentStage::Staging],
    ['testing', EnvironmentStage::Staging],
    ['qa', EnvironmentStage::Staging],
    ['feature/login', EnvironmentStage::Feature],
    ['feat-search', EnvironmentStage::Feature],
    ['local', EnvironmentStage::Local],
    ['dev', EnvironmentStage::Local],
    ['development', EnvironmentStage::Local],
    ['  production  ', EnvironmentStage::Production],
    ['demo', EnvironmentStage::Other],
    ['', EnvironmentStage::Other],
]);

it('treats a missing name as other', function () {
    expect(EnvironmentStage::fromName(null))->toBe(EnvironmentStage::Other);
});

it('reads the name prefixes from config', function () {
    config()->set('filament-voight.environments.stages', [
        'acceptance' => ['klant-acc'],
    ]);

    expect(EnvironmentStage::fromName('klant-acc'))->toBe(EnvironmentStage::Acceptance)
        ->and(EnvironmentStage::fromName('production'))->toBe(EnvironmentStage::Other);
});

it('ignores config stages that do not exist', function () {
    config()->set('filament-voight.environments.stages', ['nightly' => ['nightly']]);

    expect(EnvironmentStage::fromName('nightly'))->toBe(EnvironmentStage::Other);
});

it('colours each stage', function () {
    expect(EnvironmentStage::Production->getColor())->toBe(Color::Orange)
        ->and(EnvironmentStage::Acceptance->getColor())->toBe(Color::Yellow)
        ->and(EnvironmentStage::Staging->getColor())->toBe(Color::Green)
        ->and(EnvironmentStage::Feature->getColor())->toBe(Color::Green)
        ->and(EnvironmentStage::Local->getColor())->toBe('gray')
        ->and(EnvironmentStage::Other->getColor())->toBe('gray');
});
