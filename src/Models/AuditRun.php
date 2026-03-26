<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;

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
}
