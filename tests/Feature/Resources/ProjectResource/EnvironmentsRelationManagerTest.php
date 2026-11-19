<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\HostingTag;
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
        ->callTableAction('edit', $environment, data: ['url' => 'https://site.test', 'hostings' => ['Combell']])
        ->assertHasNoTableActionErrors();

    expect($environment->refresh()->url)->toBe('https://site.test');
});

it('asks for the hostings when an environment is created, reusing existing tags whatever the case', function () {
    $project = Project::factory()->create();
    HostingTag::factory()->create(['name' => ['en' => 'Combell']]);
    $relationManager = fn () => Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ]);

    $relationManager()
        ->callTableAction('create', data: ['name' => 'production', 'hostings' => []])
        ->assertHasTableActionErrors(['hostings' => 'required']);

    $relationManager()
        ->callTableAction('create', data: ['name' => 'production', 'hostings' => ['combell', 'Shared server']])
        ->assertHasNoTableActionErrors();

    expect($project->environments()->sole()->hostings->pluck('name')->sort()->values()->all())->toBe(['Combell', 'Shared server'])
        ->and(HostingTag::query()->count())->toBe(2);
});

it('shows each environment\'s hostings, or unknown', function () {
    $project = Project::factory()->create();
    $hosted = Environment::factory()->for($project)->create(['name' => 'production']);
    $hosted->syncHostings(['Combell']);
    Environment::factory()->for($project)->create(['name' => 'staging']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableColumnStateSet('hostings', ['Combell'], $hosted)
        ->assertSeeText(voightTrans('models.environment.unknown_hosting'));
});
