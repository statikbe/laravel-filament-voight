<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;

/**
 * @extends Factory<VulnerablePackageRange>
 */
class VulnerablePackageRangeFactory extends Factory
{
    protected $model = VulnerablePackageRange::class;

    public function definition(): array
    {
        return [
            'vulnerability_id' => Vulnerability::factory(),
            'package_id' => Package::factory(),
            'affected_range' => '>=1.0.0 <2.0.0',
            'fixed_version' => '2.0.0',
        ];
    }
}
