<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Team;

/**
 * @extends Factory<AlertRecipient>
 */
class AlertRecipientFactory extends Factory
{
    protected $model = AlertRecipient::class;

    public function definition(): array
    {
        $team = Team::factory()->create();

        return [
            'alert_setting_id' => AlertSetting::factory(),
            'recipient_type' => $team->getMorphClass(),
            'recipient_id' => $team->getKey(),
        ];
    }

    public function forRecipient(Model $recipient): static
    {
        return $this->state([
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
        ]);
    }
}
