<?php

use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;

it('casts a locks scan and links it to its environment', function () {
    $environment = Environment::factory()->create();
    $scan = OsvScan::factory()->create(['environment_id' => $environment->id, 'meta' => ['project_code' => 'p1']]);

    $scan->refresh();

    expect($scan->type)->toBe(OsvScanType::Locks)
        ->and($scan->status)->toBe(OsvScanStatus::Pending)
        ->and($scan->trigger)->toBe(AuditRunTrigger::Manual)
        ->and($scan->meta)->toBe(['project_code' => 'p1'])
        ->and($scan->resubmits)->toBe(0)
        ->and($scan->environment->is($environment))->toBeTrue()
        ->and(strlen($scan->id))->toBe(26);
});

it('groups packages scans under a nightly sweep', function () {
    $sweep = NightlySweep::factory()->create(['environment_ids' => ['a', 'b']]);
    OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id]);

    $sweep->refresh();

    expect($sweep->status)->toBe(NightlySweepStatus::Scanning)
        ->and($sweep->environment_ids)->toBe(['a', 'b'])
        ->and($sweep->scans)->toHaveCount(2)
        ->and($sweep->scans->first()->type)->toBe(OsvScanType::Packages)
        ->and($sweep->scans->first()->nightlySweep->is($sweep))->toBeTrue();
});

it('deletes an environment\'s scans with the environment', function () {
    $scan = OsvScan::factory()->create();

    $scan->environment->delete();

    expect(OsvScan::count())->toBe(0);
});
