<?php

namespace Statikbe\FilamentVoight\Notifications;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AlertSetting;

class AlertDispatcher
{
    /**
     * Send the notification over the setting's channel. Returns true when at
     * least one message was actually handed to a transport.
     */
    public function send(AlertSetting $setting, AuditAlertNotification $notification): bool
    {
        return match ($setting->channel) {
            AlertChannel::Email => $this->sendMail($setting, $notification),
            AlertChannel::Slack => $this->sendSlack($setting, $notification),
        };
    }

    private function sendMail(AlertSetting $setting, AuditAlertNotification $notification): bool
    {
        $recipients = $setting->resolveEmailRecipients();

        if ($recipients->isEmpty()) {
            Log::warning('[Voight] Alert skipped: no email recipients resolved', [
                'alert_setting' => $setting->id,
            ]);

            return false;
        }

        Notification::sendNow($recipients, $notification);

        return true;
    }

    private function sendSlack(AlertSetting $setting, AuditAlertNotification $notification): bool
    {
        $channel = $setting->slack_channel
            ?? FilamentVoight::config()->getSlackDefaultChannel()
            ?? config('services.slack.notifications.channel');

        if (blank($channel)) {
            Log::warning('[Voight] Alert skipped: no Slack channel configured', [
                'alert_setting' => $setting->id,
            ]);

            return false;
        }

        Notification::sendNow(Notification::route('slack', $channel), $notification);

        return true;
    }
}
