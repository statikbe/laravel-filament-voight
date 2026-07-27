<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that an alert setting has already reported a given vulnerability/package
 * pair, so immediate alerts never repeat a finding the recipients have seen.
 *
 * @property string $id
 * @property string $alert_setting_id
 * @property string $vulnerability_id
 * @property string $package_id
 * @property Carbon $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AlertNotificationLog extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_alert_notification_logs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AlertSetting, $this>
     */
    public function alertSetting(): BelongsTo
    {
        return $this->belongsTo(AlertSetting::class);
    }

    /**
     * @return BelongsTo<Vulnerability, $this>
     */
    public function vulnerability(): BelongsTo
    {
        return $this->belongsTo(Vulnerability::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
