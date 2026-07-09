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

    /**
     * Subquery selecting, for each project, the id of its single most recent AuditRun
     * across all of the project's environments (by started_at, id as tie-breaker).
     *
     * Intended for use in `whereIn('id', AuditRun::latestIdsPerProject())`.
     *
     * @return Builder<AuditRun>
     */
    public static function latestIdsPerProject(): Builder
    {
        return self::query()
            ->select('voight_audit_runs.id')
            ->join('voight_environments', 'voight_audit_runs.environment_id', '=', 'voight_environments.id')
            ->whereRaw('voight_audit_runs.id = (
                select ar.id
                from voight_audit_runs ar
                join voight_environments env on ar.environment_id = env.id
                where env.project_id = voight_environments.project_id
                order by ar.started_at desc, ar.id desc
                limit 1
            )');
    }
}
