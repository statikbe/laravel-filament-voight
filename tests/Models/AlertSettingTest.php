<?php

use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Project;

it('can be created via factory', function () {
    $setting = AlertSetting::factory()->create();

    expect($setting)->toBeInstanceOf(AlertSetting::class);
});

it('casts channel and frequency to enums', function () {
    $setting = AlertSetting::factory()->create();

    expect($setting->channel)->toBe(AlertChannel::Email)
        ->and($setting->frequency)->toBe(AlertFrequency::Daily);
});

it('belongs to a project', function () {
    $setting = AlertSetting::factory()->create();

    expect($setting->project)->toBeInstanceOf(Project::class);
});

it('allows null project_id for global settings', function () {
    $setting = AlertSetting::factory()->global()->create();

    expect($setting->project_id)->toBeNull()
        ->and($setting->project)->toBeNull();
});

it('casts is_enabled to boolean', function () {
    $setting = AlertSetting::factory()->create(['is_enabled' => false]);

    expect($setting->is_enabled)->toBeFalse()->toBeBool();
});
