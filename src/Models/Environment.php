<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;

/**
 * @property string $id
 * @property int $project_id
 * @property string $name
 * @property bool $scan_nightly
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
     * Relations EnvironmentHealthService reads; eager load them to avoid a query per environment.
     *
     * @var array<int, string>
     */
    public const array HEALTH_RELATIONS = ['latestFinishedDependencySync', 'latestFinishedAuditRun'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scan_nightly' => 'boolean',
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

    /**
     * The most recent sync that completed or failed; one still in progress is ignored.
     *
     * @return HasOne<DependencySync, $this>
     */
    public function latestFinishedDependencySync(): HasOne
    {
        return $this->hasOne(DependencySync::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->whereIn('status', [DependencySyncStatus::Completed, DependencySyncStatus::Failed]),
        );
    }

    /**
     * The most recent audit run that completed or failed; one still in progress is ignored.
     *
     * @return HasOne<AuditRun, $this>
     */
    public function latestFinishedAuditRun(): HasOne
    {
        return $this->hasOne(AuditRun::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->whereIn('status', [AuditRunStatus::Completed, AuditRunStatus::Failed]),
        );
    }
}
