<?php

use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ViewTeam;
use Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers\ProjectsRelationManager;
use Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers\UsersRelationManager;
use Statikbe\FilamentVoight\Tests\Support\User;

beforeEach(function () {
    $this->actingAs(new User);
});

function teamUsersManager(Team $team): Testable
{
    return Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ]);
}

it('attaches a user on the team view page', function () {
    $team = Team::factory()->create();
    $user = mcpUser();

    teamUsersManager($team)
        ->callAction(TestAction::make('attach')->table(), ['recordId' => $user->getKey()])
        ->assertHasNoActionErrors();

    expect($team->users()->whereKey($user->getKey())->exists())->toBeTrue();
});

it('detaches a user on the team view page', function () {
    $team = Team::factory()->create();
    $user = mcpUser($team);

    teamUsersManager($team)
        ->callAction(TestAction::make('detach')->table($user))
        ->assertHasNoActionErrors();

    expect($team->users()->count())->toBe(0);
});

it('shows the team name in the heading and counts as tab badges', function () {
    $team = Team::factory()->create(['name' => 'Backend']);
    mcpUser($team);
    mcpUser($team);
    Project::factory()->for($team)->create();
    Project::factory()->create();

    expect(UsersRelationManager::getBadge($team, ViewTeam::class))->toBe('2')
        ->and(ProjectsRelationManager::getBadge($team, ViewTeam::class))->toBe('1');

    Livewire::test(ViewTeam::class, ['record' => $team->getRouteKey()])
        ->assertSeeHtml(e($team->getAvatarUrl()))
        ->assertSee('Backend');
});

it('lists only the team projects without a team column', function () {
    $team = Team::factory()->create();
    $mine = Project::factory()->for($team)->create();
    $other = Project::factory()->create();

    Livewire::test(ProjectsRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableColumnHidden('team.name');
});

it('renders the relation manager tabs on the team page', function () {
    $team = Team::factory()->create();

    Livewire::test(ViewTeam::class, ['record' => $team->getRouteKey()])
        ->assertSee(voightTrans('models.team.fields.users'))
        ->assertSee(voightTrans('models.project.plural'))
        ->assertDontSee('Overview');
});
