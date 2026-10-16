<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // The suffix keeps names unique per project (unique index); the stage detection only looks at the prefix.
            'name' => fake()->randomElement(['production', 'staging', 'development']) . '-' . fake()->unique()->numberBetween(1, 999999),
            'scan_nightly' => true,
            'scanned_at' => null,
        ];
    }

    public function scanned(): static
    {
        return $this->state(['scanned_at' => now()]);
    }

    public function notNightly(): static
    {
        return $this->state(['scan_nightly' => false]);
    }
}
