<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Hosting;
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
        ->callTableAction('edit', $environment, data: ['url' => 'https://site.test', 'hosting_id' => Hosting::factory()->create()->getKey()])
        ->assertHasNoTableActionErrors();

    expect($environment->refresh()->url)->toBe('https://site.test');
});

it('asks for the hosting when an environment is created', function () {
    $project = Project::factory()->create();
    $combell = Hosting::factory()->create(['name' => 'Combell']);
    $relationManager = fn () => Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ]);

    $relationManager()
        ->callTableAction('create', data: ['name' => 'production'])
        ->assertHasTableActionErrors(['hosting_id' => 'required']);

    $relationManager()
        ->callTableAction('create', data: ['name' => 'production', 'hosting_id' => $combell->getKey()])
        ->assertHasNoTableActionErrors();

    expect($project->environments()->sole()->hosting->is($combell))->toBeTrue();
});

it('shows each environment\'s hosting, or unknown', function () {
    $project = Project::factory()->create();
    $hosted = Environment::factory()->for($project)->for(Hosting::factory()->state(['name' => 'Combell']))->create(['name' => 'production']);
    $unknown = Environment::factory()->for($project)->create(['name' => 'staging']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableColumnStateSet('hosting.name', 'Combell', $hosted)
        ->assertSeeText(voightTrans('models.environment.unknown_hosting'));
});
