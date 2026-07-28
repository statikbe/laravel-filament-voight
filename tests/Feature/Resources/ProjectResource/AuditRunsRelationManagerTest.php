<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AuditRunsRelationManager;

beforeEach(function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

it('lists runs from every environment of the project', function () {
    $project = Project::factory()->create();
    $production = AuditRun::factory()->for(Environment::factory()->for($project)->create())->create();
    $staging = AuditRun::factory()->for(Environment::factory()->for($project)->create())->create();

    Livewire::test(AuditRunsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$production, $staging]);
});

it('excludes runs belonging to another project', function () {
    $project = Project::factory()->create();
    $mine = AuditRun::factory()->for(Environment::factory()->for($project)->create())->create();
    $theirs = AuditRun::factory()->create();

    Livewire::test(AuditRunsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
