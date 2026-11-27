<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Concerns\HasVersionKeys;

/**
 * @property string $id
 * @property string $audit_run_id
 * @property string $package_id
 * @property string $vulnerability_id
 * @property string $installed_version
 * @property string|null $installed_version_sort
 * @property string|null $fixed_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditFinding extends Model
{
    use HasFactory;
    use HasUlids;
    use HasVersionKeys;

    protected $table = 'voight_audit_findings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function versionKeyColumns(): array
    {
        return ['installed_version' => 'installed_version_sort'];
    }

    /**
     * Restricts findings to those of an environment's latest audit run.
     *
     * @param  Builder<AuditFinding>  $query
     * @return Builder<AuditFinding>
     */
    public static function constrainToCurrent(Builder $query): Builder
    {
        // Once per request (flushed between Octane requests and tests): the correlated subquery is slow on shared hosting.
        $runIds = once(fn (): array => FilamentVoight::config()->getAuditRunModel()::latestIdsPerEnvironment()->pluck('id')->all());

        return $query->whereIn('audit_run_id', $runIds);
    }

    /**
     * Restricts findings to those of an environment's latest completed audit run.
     *
     * @param  Builder<AuditFinding>  $query
     * @return Builder<AuditFinding>
     */
    public static function constrainToLatestCompleted(Builder $query): Builder
    {
        return $query->whereIn('audit_run_id', FilamentVoight::config()->getAuditRunModel()::latestCompletedIdsPerEnvironment());
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
