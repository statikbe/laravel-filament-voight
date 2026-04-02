<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\PackageType;

/**
 * @property string $id
 * @property string $name
 * @property PackageType $type
 * @property string|null $latest_version
 * @property Carbon|null $latest_version_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
