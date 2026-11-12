<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lets environments be edited from the project view page', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionVisible('edit', $environment)
        ->callTableAction('edit', $environment, data: ['url' => 'https://site.test'])
        ->assertHasNoTableActionErrors();

    expect($environment->refresh()->url)->toBe('https://site.test');
});
