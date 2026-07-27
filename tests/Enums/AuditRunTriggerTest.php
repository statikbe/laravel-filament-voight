<?php

use Statikbe\FilamentVoight\Enums\AuditRunTrigger;

// The Filament contracts (HasLabel/HasColor/HasIcon) and exhaustive color/icon
// maps are covered generically for every enum in EnumContractsTest. Here we only
// assert what is specific to this enum: its backed values and options().

it('exposes the three trigger cases with string values', function () {
    expect(AuditRunTrigger::PostSync->value)->toBe('post_sync')
        ->and(AuditRunTrigger::Nightly->value)->toBe('nightly')
        ->and(AuditRunTrigger::Manual->value)->toBe('manual');
});

it('produces options keyed by the backed value', function () {
    expect(AuditRunTrigger::options())->toHaveKeys(['post_sync', 'nightly', 'manual']);
});
