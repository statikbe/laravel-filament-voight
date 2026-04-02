<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;

/**
 * @property string $id
 * @property int|null $project_id
 * @property AlertChannel $channel
 * @property float $severity_threshold
 * @property AlertFrequency $frequency
 * @property string|null $webhook_url
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AlertSetting extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_alert_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => AlertChannel::class,
            'severity_threshold' => 'decimal:1',
            'frequency' => AlertFrequency::class,
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
