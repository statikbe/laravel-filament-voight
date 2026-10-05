<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Models\NightlySweep;

/**
 * @extends Factory<NightlySweep>
 */
class NightlySweepFactory extends Factory
{
    protected $model = NightlySweep::class;

    public function definition(): array
    {
        return [
            'status' => NightlySweepStatus::Scanning,
            'environment_ids' => [],
            'started_at' => now(),
        ];
    }
}
