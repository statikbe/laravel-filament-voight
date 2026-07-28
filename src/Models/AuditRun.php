<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;

/**
 * @property string $id
 * @property string $environment_id
 * @property AuditRunStatus $status
 * @property AuditRunTrigger|null $trigger
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property-read string|null $formatted_duration
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
            'trigger' => AuditRunTrigger::class,
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
     * Human-readable run duration, or null when the run has not both started and finished.
     */
    protected function formattedDuration(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->buildFormattedDuration());
    }

    private function buildFormattedDuration(): ?string
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        $totalSeconds = (int) max(0, $this->started_at->diffInSeconds($this->completed_at));
        $minutes = intdiv($totalSeconds, 60);
        $seconds = $totalSeconds % 60;

        return $minutes > 0
            ? sprintf('%dm %ds', $minutes, $seconds)
            : sprintf('%ds', $seconds);
    }

    /**
     * Every vulnerability found by this run, reached through its findings.
     *
     * Yields one row per finding, so the same vulnerability can appear twice.
     * Intended for aggregation (worst severity), not for listing.
     *
     * @return HasManyThrough<Vulnerability, AuditFinding, $this>
     */
    public function vulnerabilities(): HasManyThrough
    {
        return $this->hasManyThrough(
            Vulnerability::class,
            AuditFinding::class,
            'audit_run_id',
            'id',
            'id',
            'vulnerability_id',
        );
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
