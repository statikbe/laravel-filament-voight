<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Package;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word() . '/' . fake()->word(),
            'type' => fake()->randomElement(PackageType::cases()),
            'latest_version' => fake()->numerify('#.#.#'),
            'latest_version_updated_at' => null,
        ];
    }

    public function composer(): static
    {
        return $this->state(['type' => PackageType::Composer]);
    }

    public function npm(): static
    {
        return $this->state(['type' => PackageType::Npm]);
    }
}
