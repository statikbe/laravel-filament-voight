<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
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

    // Names must be explicit: the factory picks randomly and (project_id, name)
    // is unique, so two random environments on one project can collide.
    $production = AuditRun::factory()
        ->for(Environment::factory()->for($project)->create(['name' => 'production']))
        ->create();
    $staging = AuditRun::factory()
        ->for(Environment::factory()->for($project)->create(['name' => 'staging']))
        ->create();

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

it('links the row action to the audit run page instead of an empty modal', function () {
    $project = Project::factory()->create();
    $run = AuditRun::factory()->for(Environment::factory()->for($project)->create())->create();

    // Without $relatedResource, Filament cannot build a URL and falls back to a
    // modal that a relation manager has no infolist to fill.
    Livewire::test(AuditRunsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionHasUrl('view', AuditRunResource::getUrl('view', ['record' => $run]), $run);
});
