<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;

/**
 * @extends Factory<AuditFinding>
 */
class AuditFindingFactory extends Factory
{
    protected $model = AuditFinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audit_run_id' => AuditRun::factory(),
            'package_id' => Package::factory(),
            'vulnerability_id' => Vulnerability::factory(),
            'installed_version' => '1.0.0',
            'fixed_version' => '2.0.0',
        ];
    }
}
