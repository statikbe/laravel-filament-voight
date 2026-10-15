<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAs(new User);
});

it('shows the stored favicon in the projects table', function () {
    Storage::disk('public')->put('voight/favicons/1-1.png', 'png');
    $project = Project::factory()->create(['favicon_path' => 'voight/favicons/1-1.png']);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertTableColumnExists('favicon')
        ->assertSee(Storage::disk('public')->url('voight/favicons/1-1.png'));
});

it('shows a placeholder when a project has no favicon', function () {
    $project = Project::factory()->create(['favicon_path' => null]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertSee($project->getFaviconPlaceholderUrl(), escape: false);
});

it('shows the project favicon in the environments table', function () {
    Storage::disk('public')->put('voight/favicons/1-1.png', 'png');
    $project = Project::factory()->create(['favicon_path' => 'voight/favicons/1-1.png']);
    $environment = Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$environment])
        ->assertSee(Storage::disk('public')->url('voight/favicons/1-1.png'))
        ->assertSee('https://site.test');
});

it('builds a placeholder from the project name initial', function () {
    $project = Project::factory()->make(['name' => 'acme site', 'project_code' => 'acme']);

    $svg = base64_decode(str($project->getFaviconPlaceholderUrl())->after('base64,'));

    expect($svg)->toContain('>A</text>')
        ->and($project->getFaviconPlaceholderUrl())->toBe($project->getFaviconPlaceholderUrl());
});
