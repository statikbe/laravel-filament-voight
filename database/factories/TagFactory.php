<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => fn (): string => TagType::factory()->create()->key,
        ];
    }

    public function ofType(TagType $type): static
    {
        return $this->state(['type' => $type->key]);
    }
}
