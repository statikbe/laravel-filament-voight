<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
