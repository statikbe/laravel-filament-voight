<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * @extends Factory<TagType>
 */
class TagTypeFactory extends Factory
{
    protected $model = TagType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'is_single' => false,
            'taggable_types' => null,
        ];
    }

    public function single(): static
    {
        return $this->state(['is_single' => true]);
    }

    /**
     * @param  list<string>  $morphAliases
     */
    public function appliesTo(array $morphAliases): static
    {
        return $this->state(['taggable_types' => $morphAliases]);
    }
}
