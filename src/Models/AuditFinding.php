<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\Severity;

/**
 * @property string $id
 * @property string $audit_run_id
 * @property string $package_id
 * @property string $vulnerability_id
 * @property string $installed_version
 * @property string|null $fixed_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditFinding extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_audit_findings';

    protected $guarded = [];

    /**
     * Findings that are still open: those of the latest audit run of each environment.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('audit_run_id'), AuditRun::latestIdsPerEnvironment());
    }

    /**
     * The open findings among the given ones with the worst severity among them. One query.
     *
     * @param  Builder<static>|Relation<static, *, *>  $findings
     * @return array{count: int, severity: Severity|null}
     */
    public static function openSummary(Builder | Relation $findings): array
    {
        $row = $findings
            ->open()
            ->join('voight_vulnerabilities', 'voight_vulnerabilities.id', '=', 'voight_audit_findings.vulnerability_id')
            ->toBase()
            ->selectRaw('count(*) as total, max(voight_vulnerabilities.vulnerability_score) as max_score')
            ->first();

        $count = (int) ($row->total ?? 0);

        return [
            'count' => $count,
            'severity' => $count > 0 ? Severity::fromScore((float) $row->max_score) : null,
        ];
    }

    /**
     * @return BelongsTo<AuditRun, $this>
     */
    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<Vulnerability, $this>
     */
    public function vulnerability(): BelongsTo
    {
        return $this->belongsTo(Vulnerability::class);
    }
}
