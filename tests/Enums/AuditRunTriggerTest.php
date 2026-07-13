<?php

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;

it('implements the Filament label, color and icon contracts', function () {
    $case = AuditRunTrigger::Nightly;
    expect($case)->toBeInstanceOf(HasLabel::class)
        ->toBeInstanceOf(HasColor::class)
        ->toBeInstanceOf(HasIcon::class)
        ->and($case->getColor())->toBe('gray')
        ->and($case->getIcon())->toBe('heroicon-o-moon')
        ->and($case->getLabel())->toBe($case->label());
});

it('exposes the three trigger cases with string values', function () {
    expect(AuditRunTrigger::PostSync->value)->toBe('post_sync')
        ->and(AuditRunTrigger::Nightly->value)->toBe('nightly')
        ->and(AuditRunTrigger::Manual->value)->toBe('manual');
});

it('produces options keyed by value with string labels', function () {
    // NOTE: package translation namespaces are not loaded under Testbench, so
    // label() returns the translation key rather than the resolved English
    // string. Asserting resolved copy is impossible here (the existing
    // package_type enum behaves identically). We assert structure + type.
    $options = AuditRunTrigger::options();
    expect($options)->toHaveKeys(['post_sync', 'nightly', 'manual'])
        ->and($options['nightly'])->toBeString()
        ->and(AuditRunTrigger::Nightly->color())->toBe('gray');
});
