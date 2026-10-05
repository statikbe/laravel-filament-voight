<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
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

it('returns the configured alert mailer', function () {
    config()->set('filament-voight.notifications.mailer', 'postmark');

    expect(FilamentVoight::config()->getAlertMailer())->toBe('postmark');
});

it('returns a null alert mailer so the host app default is used', function () {
    config()->set('filament-voight.notifications.mailer', null);

    expect(FilamentVoight::config()->getAlertMailer())->toBeNull();
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

it('allows uploading the composer.json and package.json manifests by default', function () {
    $lockfilesConfig = config('filament-voight.lockfiles');
    unset($lockfilesConfig['allowed_names']);
    config()->set('filament-voight.lockfiles', $lockfilesConfig);

    expect(FilamentVoight::config()->getAllowedLockfileNames())->toContain('composer.json', 'package.json');
});

it('allows composer.json in the published config', function () {
    $publishedConfig = require dirname(__DIR__, 2) . '/config/filament-voight.php';

    expect($publishedConfig['lockfiles']['allowed_names'])->toContain('composer.json');
});

it('reads the async scanner settings with spec defaults', function () {
    config()->set('filament-voight.scanner.poll_interval', null);
    config()->set('filament-voight.scanner.max_resubmits', null);
    config()->set('filament-voight.scanner.callback_secret', '');

    expect(FilamentVoight::config()->getScannerPollInterval())->toBe(15)
        ->and(FilamentVoight::config()->getScannerMaxResubmits())->toBe(2)
        ->and(FilamentVoight::config()->getScannerCallbackSecret())->toBeNull();

    config()->set('filament-voight.scanner.poll_interval', 5);
    config()->set('filament-voight.scanner.max_resubmits', 0);
    config()->set('filament-voight.scanner.callback_secret', 's3cret');

    expect(FilamentVoight::config()->getScannerPollInterval())->toBe(5)
        ->and(FilamentVoight::config()->getScannerMaxResubmits())->toBe(0)
        ->and(FilamentVoight::config()->getScannerCallbackSecret())->toBe('s3cret');
});

it('resolves the scan models from config', function () {
    expect(FilamentVoight::config()->getOsvScanModel())->toBe(OsvScan::class)
        ->and(FilamentVoight::config()->getNightlySweepModel())->toBe(NightlySweep::class);
});

it('turns callbacks off with an empty callback url', function () {
    config()->set('filament-voight.scanner.callback_url', '');

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBeNull();
});

it('uses a configured callback url as-is', function () {
    config()->set('filament-voight.scanner.callback_url', 'https://voight.example/cb');

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBe('https://voight.example/cb');
});

it('turns callbacks off with a warning when the configured callback url is not https', function () {
    Log::spy();
    config()->set('filament-voight.scanner.callback_url', 'http://voight.example/cb');

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'callbacks are disabled') && str_contains($message, 'https'));
});

it('defaults the callback url to the package callback route', function () {
    Route::post('api/voight/scans/callback', fn () => null)->name('voight.scans.callback');
    Route::getRoutes()->refreshNameLookups();
    URL::forceRootUrl('https://voight.test');
    URL::forceScheme('https');
    config()->set('filament-voight.scanner.callback_url', null);

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBe('https://voight.test/api/voight/scans/callback');
});

it('turns callbacks off with a warning when the default callback route is not https', function () {
    Log::spy();
    Route::post('api/voight/scans/callback', fn () => null)->name('voight.scans.callback');
    Route::getRoutes()->refreshNameLookups();
    config()->set('filament-voight.scanner.callback_url', null);

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('defaults the callback url to the real registered package route', function () {
    URL::forceScheme('https');
    config()->set('filament-voight.scanner.callback_url', null);

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBe(url('/api/voight/scans/callback'));
});
