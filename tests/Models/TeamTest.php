<?php

use Illuminate\Foundation\Auth\User;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;

it('can be created via factory', function () {
    $team = Team::factory()->create();

    expect($team)->toBeInstanceOf(Team::class)
        ->and($team->name)->toBeString();
});

it('has many projects', function () {
    $team = Team::factory()->create();
    Project::factory()->for($team)->create();

    expect($team->projects)->toHaveCount(1);
});

it('belongs to many users', function () {
    $team = Team::factory()->create();
    $user = User::forceCreate([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $team->users()->attach($user);

    expect($team->users)->toHaveCount(1);
});
