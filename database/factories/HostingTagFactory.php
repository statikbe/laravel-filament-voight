<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\HostingTag;

/**
 * @extends Factory<HostingTag>
 */
class HostingTagFactory extends Factory
{
    protected $model = HostingTag::class;

    public function definition(): array
    {
        return [
            'name' => ['en' => fake()->unique()->company()],
        ];
    }
}
