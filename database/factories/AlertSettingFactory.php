<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
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

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'channel' => AlertChannel::Email,
            'severity_threshold' => 7.0,
            'frequency' => AlertFrequency::Daily,
            'webhook_url' => null,
            'is_enabled' => true,
        ];
    }

    public function global(): static
    {
        return $this->state(['project_id' => null]);
    }

    public function slack(string $webhookUrl = 'https://hooks.slack.com/test'): static
    {
        return $this->state([
            'channel' => AlertChannel::Slack,
            'webhook_url' => $webhookUrl,
        ]);
    }
}
