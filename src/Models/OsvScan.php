<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;

/**
 * One asynchronous scan at the OSV scanner service: a lockfile scan for one
 * environment, or one package batch of a nightly sweep.
 *
 * @property string $id
 * @property string|null $scanner_scan_id
 * @property OsvScanType $type
 * @property OsvScanStatus $status
 * @property string|null $environment_id
 * @property string|null $nightly_sweep_id
 * @property AuditRunTrigger $trigger
 * @property array<string, mixed> $meta
 * @property int $resubmits
 * @property string|null $input_path
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OsvScan extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_osv_scans';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OsvScanType::class,
            'status' => OsvScanStatus::class,
            'trigger' => AuditRunTrigger::class,
            'meta' => 'array',
            'resubmits' => 'integer',
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
     * @return BelongsTo<NightlySweep, $this>
     */
    public function nightlySweep(): BelongsTo
    {
        return $this->belongsTo(NightlySweep::class);
    }
}
