<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;

/**
 * @extends Factory<OsvScan>
 */
class OsvScanFactory extends Factory
{
    protected $model = OsvScan::class;

    public function definition(): array
    {
        return [
            'scanner_scan_id' => (string) Str::ulid(),
            'type' => OsvScanType::Locks,
            'status' => OsvScanStatus::Pending,
            'environment_id' => Environment::factory(),
            'nightly_sweep_id' => null,
            'trigger' => AuditRunTrigger::Manual,
            'meta' => [],
            'resubmits' => 0,
            'input_path' => null,
        ];
    }

    public function packages(): static
    {
        return $this->state([
            'type' => OsvScanType::Packages,
            'environment_id' => null,
            'nightly_sweep_id' => NightlySweep::factory(),
            'trigger' => AuditRunTrigger::Nightly,
        ]);
    }
}
