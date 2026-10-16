<?php

use Filament\Support\Facades\FilamentColor;
use Statikbe\FilamentVoight\Enums\Severity;

it('gives critical a colour distinct from high', function () {
    expect(Severity::Critical->color())->not->toBe(Severity::High->color())
        ->and(Severity::High->color())->toBe('danger');
});

it('registers the critical colour ', function () {
    expect(FilamentColor::getColor(Severity::Critical->color()))->toBeArray()->toHaveKey(500);
});
