<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;

/**
 * @property string $id
 * @property string $environment_id
 * @property AuditRunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditRun extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_audit_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AuditRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return HasMany<AuditFinding, $this>
     */
    public function auditFindings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }

    /**
     * Subquery selecting, for each environment, the id of its most recent AuditRun (by started_at).
     *
     * Intended for use in `whereIn('audit_run_id', AuditRun::latestIdsPerEnvironment())`.
     *
     * @return Builder<AuditRun>
     */
    public static function latestIdsPerEnvironment(): Builder
    {
        return self::query()
            ->select('id')
            ->whereRaw('started_at = (select max(started_at) from voight_audit_runs a2 where a2.environment_id = voight_audit_runs.environment_id)');
    }
}
