<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'project_code' => fake()->unique()->bothify('PRJ-####'),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'repo_url' => fake()->url(),
            'customer_id' => Customer::factory(),
            'team_id' => Team::factory(),
            'is_muted' => false,
        ];
    }

    public function muted(): static
    {
        return $this->state(['is_muted' => true]);
    }
}
