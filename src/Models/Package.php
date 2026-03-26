<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Statikbe\FilamentVoight\Enums\PackageType;

class Package extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_packages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'latest_version_updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EnvironmentPackage, $this>
     */
    public function environmentPackages(): HasMany
    {
        return $this->hasMany(EnvironmentPackage::class);
    }

    /**
     * @return HasMany<VulnerablePackageRange, $this>
     */
    public function vulnerablePackageRanges(): HasMany
    {
        return $this->hasMany(VulnerablePackageRange::class);
    }

    /**
     * @return HasMany<AuditFinding, $this>
     */
    public function auditFindings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }
}
