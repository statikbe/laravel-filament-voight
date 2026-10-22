<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\CreateProject;

beforeEach(function () {
    $this->actingAs(new User);
});

it('opens the new project so an API token can be generated', function () {
    Livewire::test(CreateProject::class)
        ->fillForm([
            'project_code' => 'NEWPRJ',
            'name' => 'New project',
            'repo_url' => 'https://github.com/statikbe/new-project',
            'customer_id' => Customer::factory()->create()->id,
            'team_id' => Team::factory()->create()->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ProjectResource::getUrl('view', ['record' => Project::query()->where('project_code', 'NEWPRJ')->sole()]));
});
