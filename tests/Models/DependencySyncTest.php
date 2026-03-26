<?php

use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;

it('can be created via factory', function () {
    $sync = DependencySync::factory()->create();

    expect($sync)->toBeInstanceOf(DependencySync::class);
});

it('casts status to DependencySyncStatus enum', function () {
    $sync = DependencySync::factory()->create();

    expect($sync->status)->toBe(DependencySyncStatus::Completed);
});

it('casts lockfile_paths to array', function () {
    $sync = DependencySync::factory()->create([
        'lockfile_paths' => ['my-project/production/composer.lock'],
    ]);

    expect($sync->lockfile_paths)->toBeArray()->toContain('my-project/production/composer.lock');
});

it('belongs to an environment', function () {
    $sync = DependencySync::factory()->create();

    expect($sync->environment)->toBeInstanceOf(Environment::class);
});
