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

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['production', 'staging', 'development']),
            'scanned_at' => null,
        ];
    }

    public function scanned(): static
    {
        return $this->state(['scanned_at' => now()]);
    }
}
