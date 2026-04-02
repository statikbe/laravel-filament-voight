<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property string $name
 * @property Carbon|null $scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Environment extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_environments';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<EnvironmentPackage, $this>
     */
    public function environmentPackages(): HasMany
    {
        return $this->hasMany(EnvironmentPackage::class);
    }

    /**
     * @return HasMany<DependencySync, $this>
     */
    public function dependencySyncs(): HasMany
    {
        return $this->hasMany(DependencySync::class);
    }

    /**
     * @return HasMany<AuditRun, $this>
     */
    public function auditRuns(): HasMany
    {
        return $this->hasMany(AuditRun::class);
    }
}
