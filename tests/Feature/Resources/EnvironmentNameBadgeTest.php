<?php

use Filament\Support\Colors\Color;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameEntry;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('renders environment names as badges coloured by stage', function () {
    $column = EnvironmentNameColumn::make('name');
    $entry = EnvironmentNameEntry::make('name');

    expect($column->isBadge())->toBeTrue()
        ->and($column->getColor('production'))->toBe(Color::Orange)
        ->and($column->getColor('feature/login'))->toBe(Color::Green)
        ->and($entry->isBadge())->toBeTrue()
        ->and($entry->getColor('acc'))->toBe(Color::Yellow)
        ->and($entry->getColor('local'))->toBe('gray');
});

it('shows the environment badges on the project environments tab', function () {
    $project = Project::factory()->create();
    $production = Environment::factory()->for($project)->create(['name' => 'production']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('name', 'production', $production)
        ->assertSeeHtml('fi-badge');
});

it('shows the environment badge on the audit run page', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($run->environment->name);
});
