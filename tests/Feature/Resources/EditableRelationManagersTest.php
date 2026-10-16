<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ViewCustomer;
use Statikbe\FilamentVoight\Resources\CustomerResource\RelationManagers\ProjectRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ViewTeam;
use Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers\UsersRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('keeps relation managers editable on the view page', function (string $manager, string $page, Closure $owner, TestAction $action) {
    Livewire::test($manager, ['ownerRecord' => $owner(), 'pageClass' => $page])
        ->assertActionVisible($action);
})->with([
    'alert settings create' => [AlertSettingsRelationManager::class, ViewProject::class, fn () => Project::factory()->create(), TestAction::make('create')->table()],
    'environments create' => [EnvironmentsRelationManager::class, ViewProject::class, fn () => Project::factory()->create(), TestAction::make('create')->table()],
    'team users attach' => [UsersRelationManager::class, ViewTeam::class, fn () => Team::factory()->create(), TestAction::make('attach')->table()],
]);

it('lets environments be edited from the project view page', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();

    Livewire::test(EnvironmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->assertActionVisible(TestAction::make('edit')->table($environment));
});

it('lets projects be edited from the customer view page', function () {
    $customer = Customer::factory()->create();
    $project = Project::factory()->for($customer)->create();

    Livewire::test(ProjectRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertActionVisible(TestAction::make('edit')->table($project));
});
