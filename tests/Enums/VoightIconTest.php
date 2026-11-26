<?php

use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\FilamentVoightServiceProvider;
use Statikbe\FilamentVoight\Support\IconDefinition;

it('defines every case with exactly one IconDefinition and a description', function () {
    foreach (VoightIcon::cases() as $case) {
        $attributes = (new ReflectionEnumBackedCase(VoightIcon::class, $case->name))->getAttributes(IconDefinition::class);

        expect($attributes)->toHaveCount(1, "{$case->name} needs one #[IconDefinition]")
            ->and($case->description())->not->toBe('')
            ->and($case->default())->not->toBe('');
    }
});

it('uses unique dot-notation values', function () {
    $values = array_map(fn (VoightIcon $case): string => $case->value, VoightIcon::cases());

    expect($values)->toBe(array_values(array_unique($values)));

    foreach ($values as $value) {
        expect($value)->toMatch('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/');
    }
});

it('prefixes the alias with the voight namespace', function () {
    expect(VoightIcon::SeverityCritical->alias())->toBe('voight::severity.critical');
});

it('resolves to the default when nothing is registered', function () {
    expect(VoightIcon::SeverityCritical->resolve())->toBe('heroicon-o-fire')
        ->and(VoightIcon::SeverityCritical->isOverridden())->toBeFalse();
});

it('builds overrides from config, prefixing aliases and ignoring unknown keys', function () {
    config()->set('filament-voight.icons', [
        'severity.critical' => Heroicon::OutlinedBolt,
        'does.not-exist' => 'heroicon-o-star',
    ]);

    expect(VoightIcon::configOverrides())->toBe(['voight::severity.critical' => Heroicon::OutlinedBolt]);
});

it('lets a config override win over the default', function () {
    config()->set('filament-voight.icons', ['navigation.projects' => Heroicon::OutlinedFire]);

    FilamentIcon::register(VoightIcon::configOverrides());

    expect(VoightIcon::NavigationProjects->resolve())->toBe(Heroicon::OutlinedFire)
        ->and(VoightIcon::NavigationProjects->isOverridden())->toBeTrue();
});

it('lets an app FilamentIcon registration win over config', function () {
    config()->set('filament-voight.icons', ['navigation.projects' => Heroicon::OutlinedFire]);

    FilamentIcon::register(VoightIcon::configOverrides());
    FilamentIcon::register(['voight::navigation.projects' => 'heroicon-o-star']);

    expect(VoightIcon::NavigationProjects->resolve())->toBe('heroicon-o-star');
});

it('registers config overrides as the package icons', function () {
    config()->set('filament-voight.icons', ['severity.low' => 'heroicon-o-star']);

    $getIcons = new ReflectionMethod(FilamentVoightServiceProvider::class, 'getIcons');

    expect($getIcons->invoke(new FilamentVoightServiceProvider(app())))
        ->toBe(['voight::severity.low' => 'heroicon-o-star']);
});
