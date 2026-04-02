<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;

/**
 * @extends Factory<DependencySync>
 */
class DependencySyncFactory extends Factory
{
    protected $model = DependencySync::class;

    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'lockfile_hash' => fake()->sha256(),
            'lockfile_paths' => null,
            'package_count' => fake()->numberBetween(10, 200),
            'status' => DependencySyncStatus::Completed,
            'error_message' => null,
            'synced_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state([
            'status' => DependencySyncStatus::Failed,
            'error_message' => fake()->sentence(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'status' => DependencySyncStatus::Pending,
            'synced_at' => null,
        ]);
    }
}
