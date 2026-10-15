<?php

use Filament\Actions\Testing\TestAction;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;

beforeEach(function () {
    $this->actingAs(new class extends User implements FilamentUser
    {
        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    });
});

it('generates a project token from the project page and keeps existing tokens', function () {
    $project = Project::factory()->create();
    $project->createToken('existing');

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->callAction(TestAction::make('generate_token')->schemaComponent('api_token', schema: 'infolist'))
        ->assertNotified();

    expect($project->tokens()->pluck('name')->sort()->values()->all())->toBe([Project::DEFAULT_API_TOKEN_NAME, 'existing']);
});
