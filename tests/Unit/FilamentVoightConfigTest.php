<?php

use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Tests\Support\User;

it('resolves the user model from the models.user config', function () {
    config()->set('filament-voight.models.user', User::class);

    expect(FilamentVoight::config()->getUserModel())->toBe(User::class);
});

it('falls back to the auth provider user model when models.user is null', function () {
    config()->set('filament-voight.models.user', null);
    config()->set('auth.providers.users.model', User::class);

    expect(FilamentVoight::config()->getUserModel())->toBe(User::class);
});

it('falls back to the framework base user when no user model is configured', function () {
    config()->set('filament-voight.models.user', null);
    config()->set('auth.providers.users.model', null);

    expect(FilamentVoight::config()->getUserModel())->toBe(Illuminate\Foundation\Auth\User::class);
});

it('returns the configured slack default channel', function () {
    config()->set('filament-voight.notifications.slack_default_channel', '#alerts');

    expect(FilamentVoight::config()->getSlackDefaultChannel())->toBe('#alerts');
});

it('returns null when no slack default channel is configured', function () {
    config()->set('filament-voight.notifications.slack_default_channel', null);

    expect(FilamentVoight::config()->getSlackDefaultChannel())->toBeNull();
});

it('returns the mail from address and name as an array shape', function () {
    config()->set('filament-voight.notifications.mail_from_address', 'alerts@example.com');
    config()->set('filament-voight.notifications.mail_from_name', 'Voight Alerts');

    expect(FilamentVoight::config()->getAlertMailFrom())->toBe([
        'address' => 'alerts@example.com',
        'name' => 'Voight Alerts',
    ]);
});

it('returns null mail from when the address is empty', function () {
    config()->set('filament-voight.notifications.mail_from_address', null);

    expect(FilamentVoight::config()->getAlertMailFrom())->toBeNull();
});

it('returns the alerts panel id with a default of voight', function () {
    config()->set('filament-voight.notifications.panel_id', null);

    expect(FilamentVoight::config()->getAlertsPanelId())->toBe('voight');

    config()->set('filament-voight.notifications.panel_id', 'admin');

    expect(FilamentVoight::config()->getAlertsPanelId())->toBe('admin');
});

it('returns the configured alerts queue', function () {
    config()->set('filament-voight.notifications.queue', 'alerts');

    expect(FilamentVoight::config()->getAlertsQueue())->toBe('alerts');

    config()->set('filament-voight.notifications.queue', null);

    expect(FilamentVoight::config()->getAlertsQueue())->toBeNull();
});
