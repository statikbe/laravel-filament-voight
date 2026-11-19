<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Hosting;

/**
 * @extends Factory<Hosting>
 */
class HostingFactory extends Factory
{
    protected $model = Hosting::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }
}
