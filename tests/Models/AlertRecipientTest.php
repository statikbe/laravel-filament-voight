<?php

use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Tests\Support\User;

it('can be created via factory', function () {
    $recipient = AlertRecipient::factory()->create();

    expect($recipient)->toBeInstanceOf(AlertRecipient::class);
});

it('belongs to an alert setting', function () {
    $recipient = AlertRecipient::factory()->create();

    expect($recipient->alertSetting)->toBeInstanceOf(AlertSetting::class);
});

it('resolves a team recipient through the morph relationship', function () {
    $team = Team::factory()->create();
    $recipient = AlertRecipient::factory()->forRecipient($team)->create();

    expect($recipient->recipient)->toBeInstanceOf(Team::class)
        ->and($recipient->recipient->is($team))->toBeTrue();
});

it('resolves a user recipient through the morph relationship', function () {
    $user = User::forceCreate([
        'name' => 'Test User',
        'email' => 'user@example.com',
        'password' => bcrypt('password'),
    ]);
    $recipient = AlertRecipient::factory()->forRecipient($user)->create();

    expect($recipient->recipient)->toBeInstanceOf(User::class)
        ->and($recipient->recipient->is($user))->toBeTrue()
        ->and($recipient->recipient_id)->toEqual((string) $user->getKey());
});
