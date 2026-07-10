<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Project;

/**
 * @extends Factory<AlertSetting>
 */
class AlertSettingFactory extends Factory
{
    protected $model = AlertSetting::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'channel' => AlertChannel::Email,
            'severity_threshold' => 7.0,
            'frequency' => AlertFrequency::Daily,
            'is_enabled' => true,
        ];
    }

    public function global(): static
    {
        return $this->state(['project_id' => null]);
    }

    public function slack(?string $channel = '#voight-alerts'): static
    {
        return $this->state([
            'channel' => AlertChannel::Slack,
            'slack_channel' => $channel,
        ]);
    }

    public function immediate(): static
    {
        return $this->state(['frequency' => AlertFrequency::Immediate]);
    }

    public function weekly(): static
    {
        return $this->state(['frequency' => AlertFrequency::Weekly]);
    }

    public function sentAt(Carbon $at): static
    {
        return $this->state(['last_sent_at' => $at]);
    }
}
