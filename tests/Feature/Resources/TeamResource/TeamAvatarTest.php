<?php

use Filament\Forms\Components\Select;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\Components\TeamOption;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\CreateProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\CreateTeam;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ListTeams;

beforeEach(function () {
    Storage::fake('public');
    $this->actingAs(new User);
});

it('stores an uploaded avatar on the team avatar disk', function () {
    Livewire::test(CreateTeam::class)
        ->fillForm([
            'name' => 'Backend',
            'avatar_path' => UploadedFile::fake()->image('avatar.png', 256, 256),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Backend')->sole();

    expect($team->avatar_path)->toStartWith('voight/team-avatars/');
    Storage::disk('public')->assertExists($team->avatar_path);
});

it('rejects avatars larger than 2 MB', function () {
    Livewire::test(CreateTeam::class)
        ->fillForm([
            'name' => 'Backend',
            'avatar_path' => UploadedFile::fake()->image('avatar.png')->size(3000),
        ])
        ->call('create')
        ->assertHasFormErrors(['avatar_path']);
});

it('rejects svg avatars', function () {
    $svg = UploadedFile::fake()->createWithContent(
        'avatar.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    Livewire::test(CreateTeam::class)
        ->fillForm([
            'name' => 'Backend',
            'avatar_path' => $svg,
        ])
        ->call('create')
        ->assertHasFormErrors(['avatar_path']);

    expect(Team::query()->where('name', 'Backend')->exists())->toBeFalse();
});

it('creates a team without an avatar', function () {
    Livewire::test(CreateTeam::class)
        ->fillForm(['name' => 'Backend'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Team::query()->where('name', 'Backend')->sole()->avatar_path)->toBeNull();
});

it('lists teams with their avatar', function () {
    $team = Team::factory()->create();

    Livewire::test(ListTeams::class)
        ->assertCanSeeTableRecords([$team])
        ->assertTableColumnStateSet('avatar', $team->getAvatarUrl(), $team);
});

it('shows the team avatar next to the team on the projects list', function () {
    $team = Team::factory()->create(['name' => 'Backend']);
    $project = Project::factory()->for($team)->create();

    Livewire::test(ListProjects::class)
        ->assertCanSeeTableRecords([$project])
        ->assertSeeHtml(e($team->getAvatarUrl()))
        ->assertSee('Backend');
});

it('shows the team avatar on the project page', function () {
    $team = Team::factory()->create(['name' => 'Backend']);
    $project = Project::factory()->for($team)->create();

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSeeHtml(e($team->getAvatarUrl()))
        ->assertSee('Backend');
});

it('shows team avatars in the project form team select', function () {
    $team = Team::factory()->create(['name' => 'Backend']);

    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('team_id', fn (Select $field): bool => $field->isHtmlAllowed()
            && str_contains($field->getOptionLabelFromRecord($team), e($team->getAvatarUrl())));
});

it('filters the projects list by team name through the query builder', function () {
    $team = Team::factory()->create(['name' => 'Backend']);
    $match = Project::factory()->for($team)->create();
    $other = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('team', 'is', ['values' => [$team->id]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('labels team options with their avatar and name', function () {
    $team = Team::factory()->create(['name' => 'Backend']);

    expect(TeamOption::options()[$team->id])
        ->toContain(e($team->getAvatarUrl()))
        ->toContain('Backend');
});
