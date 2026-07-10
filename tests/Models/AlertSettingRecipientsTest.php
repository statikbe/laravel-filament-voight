<?php

use Illuminate\Support\Facades\Notification;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Tests\Support\User;

function makeVoightUser(string $name, string $email): User
{
    return User::forceCreate([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password'),
    ]);
}

it('has many recipients', function () {
    $setting = AlertSetting::factory()->create();
    AlertRecipient::factory()->count(2)->for($setting)->create();

    expect($setting->recipients)->toHaveCount(2);
});

it('resolves, deduplicates and filters email recipients across users and teams', function () {
    $setting = AlertSetting::factory()->create();

    $directUser = makeVoightUser('Direct', 'direct@example.com');

    $teamMemberA = makeVoightUser('Member A', 'member-a@example.com');
    $teamMemberBlank = makeVoightUser('Member Blank', '');

    $team = Team::factory()->create();
    $team->users()->attach([
        $teamMemberA->getKey(),
        $teamMemberBlank->getKey(),
        $directUser->getKey(),
    ]);

    AlertRecipient::factory()->for($setting)->forRecipient($directUser)->create();
    AlertRecipient::factory()->for($setting)->forRecipient($team)->create();

    $recipients = $setting->resolveEmailRecipients();

    expect($recipients)->toHaveCount(2)
        ->and($recipients->pluck('email')->sort()->values()->all())
        ->toBe(['direct@example.com', 'member-a@example.com']);

    $recipients->each(function (object $recipient) {
        expect($recipient)->toBeInstanceOf(User::class);
    });
});

it('returns an empty collection when there are no recipients', function () {
    $setting = AlertSetting::factory()->create();

    expect($setting->resolveEmailRecipients())->toBeEmpty();
});

it('resolves recipients as notifiable instances', function () {
    Notification::fake();

    $setting = AlertSetting::factory()->create();
    $user = makeVoightUser('Notifiable', 'notify@example.com');
    AlertRecipient::factory()->for($setting)->forRecipient($user)->create();

    $recipients = $setting->resolveEmailRecipients();

    expect($recipients)->toHaveCount(1)
        ->and($recipients->first())->toBeInstanceOf(User::class)
        ->and(method_exists($recipients->first(), 'notify'))->toBeTrue();
});
