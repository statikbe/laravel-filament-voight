<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
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

it('uses the uploaded avatar url when it has one', function () {
    Storage::fake('public');
    $team = Team::factory()->create(['avatar_path' => 'voight/team-avatars/backend.png']);

    expect($team->getAvatarUrl())->toBe(Storage::disk('public')->url('voight/team-avatars/backend.png'));
});

it('falls back to initials when it has no avatar', function () {
    $team = Team::factory()->create(['name' => 'Backend Team']);

    expect($team->getAvatarUrl())
        ->toStartWith('https://ui-avatars.com/api/')
        ->toContain('name=' . urlencode('B T'));
});

it('deletes the previous avatar file when the avatar is replaced', function () {
    Storage::fake('public');
    Storage::disk('public')->put('voight/team-avatars/old.png', 'old');
    Storage::disk('public')->put('voight/team-avatars/new.png', 'new');
    $team = Team::factory()->create(['avatar_path' => 'voight/team-avatars/old.png']);

    $team->update(['avatar_path' => 'voight/team-avatars/new.png']);

    Storage::disk('public')->assertMissing('voight/team-avatars/old.png');
    Storage::disk('public')->assertExists('voight/team-avatars/new.png');
});

it('deletes its avatar file when the team is deleted', function () {
    Storage::fake('public');
    Storage::disk('public')->put('voight/team-avatars/backend.png', 'avatar');
    $team = Team::factory()->create(['avatar_path' => 'voight/team-avatars/backend.png']);

    $team->delete();

    Storage::disk('public')->assertMissing('voight/team-avatars/backend.png');
});
