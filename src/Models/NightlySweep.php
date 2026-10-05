<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;

/**
 * One nightly deduplicated scan: its package batches are OsvScans, and it is
 * recorded for the environments snapshotted in environment_ids once every
 * batch has completed.
 *
 * @property string $id
 * @property NightlySweepStatus $status
 * @property array<int, string> $environment_ids
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NightlySweep extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_nightly_sweeps';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NightlySweepStatus::class,
            'environment_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OsvScan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(OsvScan::class);
    }
}
