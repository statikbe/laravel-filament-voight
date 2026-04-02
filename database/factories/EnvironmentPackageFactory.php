<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;

/**
 * @extends Factory<EnvironmentPackage>
 */
class EnvironmentPackageFactory extends Factory
{
    protected $model = EnvironmentPackage::class;

    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'package_id' => Package::factory(),
            'version' => fake()->numerify('#.#.#'),
            'is_direct' => true,
            'is_dev' => false,
            'parent_package_id' => null,
        ];
    }

    public function transitive(): static
    {
        return $this->state(['is_direct' => false]);
    }

    public function dev(): static
    {
        return $this->state(['is_dev' => true]);
    }
}
