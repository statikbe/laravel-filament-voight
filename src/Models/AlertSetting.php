<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * @property string $id
 * @property int|null $project_id
 * @property AlertChannel $channel
 * @property float $severity_threshold
 * @property AlertFrequency $frequency
 * @property string|null $webhook_url
 * @property string|null $slack_channel
 * @property bool $is_enabled
 * @property Carbon|null $last_sent_at
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
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<AlertRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }

    /**
     * A digest is due when it has never been sent, or when the frequency
     * interval has fully elapsed since the last send.
     */
    public function isDigestDue(): bool
    {
        if ($this->frequency === AlertFrequency::Immediate) {
            return false;
        }

        if ($this->last_sent_at === null) {
            return true;
        }

        $dueSince = $this->frequency === AlertFrequency::Daily
            ? now()->subDay()
            : now()->subWeek();

        return $this->last_sent_at->lte($dueSince);
    }

    /**
     * Expand the configured recipients (individual users and whole teams) into
     * a deduplicated collection of notifiable host-application users that have
     * an email address.
     *
     * Team members and morphed user recipients hydrate as the framework base
     * user (no Notifiable), so every id is re-queried through the resolved
     * host user model before it can be notified.
     *
     * @return Collection<int, Authenticatable>
     */
    public function resolveEmailRecipients(): Collection
    {
        $userModel = FilamentVoight::config()->getUserModel();
        $userAlias = array_search($userModel, Relation::morphMap(), true) ?: $userModel;
        $teamAlias = array_search(Team::class, Relation::morphMap(), true) ?: Team::class;

        $recipients = $this->recipients()->get();

        $directUserIds = $recipients
            ->where('recipient_type', $userAlias)
            ->pluck('recipient_id');

        $teamMemberIds = $recipients
            ->where('recipient_type', $teamAlias)
            ->pluck('recipient_id')
            ->flatMap(fn (string $teamId): Collection => Team::query()
                ->whereKey($teamId)
                ->get()
                ->flatMap(fn (Team $team): Collection => $team->users()->pluck('users.id')));

        $userIds = $directUserIds->merge($teamMemberIds)->unique()->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return $userModel::query()
            ->whereIn('id', $userIds->all())
            ->get()
            ->filter(fn (object $user): bool => filled($user->email))
            ->unique(fn (object $user): mixed => $user->getKey())
            ->values();
    }
}
