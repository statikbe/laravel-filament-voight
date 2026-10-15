<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;

/**
 * @extends Factory<EnvironmentSystemDetail>
 */
class EnvironmentSystemDetailFactory extends Factory
{
    protected $model = EnvironmentSystemDetail::class;

    public function definition(): array
    {
        $phpVersion = fake()->randomElement(['8.3.12', '8.4.1']);

        return [
            'environment_id' => Environment::factory(),
            'php_version' => $phpVersion,
            'payload' => [
                'server' => ['php' => ['version' => $phpVersion]],
                'framework' => ['name' => 'laravel', 'version' => null, 'groups' => null],
            ],
            'collected_at' => now(),
            'received_at' => now(),
        ];
    }
}
