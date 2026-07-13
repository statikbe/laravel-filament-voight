<?php

use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;

it('casts the trigger column to the enum', function () {
    $run = AuditRun::factory()->create(['trigger' => AuditRunTrigger::Nightly]);
    expect($run->refresh()->trigger)->toBe(AuditRunTrigger::Nightly);
});

it('defaults environments to scan_nightly true and supports opting out', function () {
    expect(Environment::factory()->create()->scan_nightly)->toBeTrue()
        ->and(Environment::factory()->notNightly()->create()->scan_nightly)->toBeFalse();
});
