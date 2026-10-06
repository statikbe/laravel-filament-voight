<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

function systemDetailsTest(Project $project)
{
    return Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ]);
}

it('hides the system details action without a snapshot', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();

    systemDetailsTest($project)
        ->assertTableActionHidden('systemDetails', $environment);
});

it('shows the PHP version and laravel section for an environment with a snapshot', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create([
        'system_details' => [
            'collected_at' => '2026-10-06T09:00:00+00:00',
            'server' => ['php' => ['version' => '8.3.12', 'xdebug' => false], 'system' => ['os' => 'Linux 6.1']],
            'laravel' => ['drivers' => ['queue' => 'redis']],
        ],
        'system_details_received_at' => now(),
    ]);

    systemDetailsTest($project)
        ->assertTableActionVisible('systemDetails', $environment)
        ->mountAction(TestAction::make('systemDetails')->table($environment))
        ->assertMountedActionModalSee('8.3.12')
        ->assertMountedActionModalSee('Xdebug')
        ->assertMountedActionModalSee('No')
        ->assertMountedActionModalSee('Drivers')
        ->assertMountedActionModalSee('redis');
});
