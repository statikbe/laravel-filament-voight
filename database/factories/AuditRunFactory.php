<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;

/**
 * @extends Factory<AuditRun>
 */
class AuditRunFactory extends Factory
{
    protected $model = AuditRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'status' => AuditRunStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => AuditRunStatus::Pending,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state([
            'status' => AuditRunStatus::Running,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }
}
