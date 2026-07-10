<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $alert_setting_id
 * @property string $recipient_type
 * @property string $recipient_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AlertRecipient extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_alert_recipients';

    protected $guarded = [];

    /**
     * @return BelongsTo<AlertSetting, $this>
     */
    public function alertSetting(): BelongsTo
    {
        return $this->belongsTo(AlertSetting::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
