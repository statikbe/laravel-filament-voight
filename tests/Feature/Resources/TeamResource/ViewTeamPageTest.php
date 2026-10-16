<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ViewTeam;
use Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers\UsersRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('renders the team stats without an avatar tile', function () {
    $team = Team::factory()->create(['name' => 'Blue Team']);
    mcpUser($team);
    mcpUser($team);
    mcpUser($team);
    Project::factory()->count(2)->for($team)->create();

    $page = Livewire::test(ViewTeam::class, ['record' => $team->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Blue Team')
        ->assertSee(voightTrans('models.team.fields.members'))
        ->assertSee(voightTrans('models.project.plural'))
        ->assertSee(voightTrans('models.team.fields.open_findings'))
        ->assertDontSeeHtml(e($team->getAvatarUrl()));

    expect(entryState($page, 'stat_members'))->toBe(3)
        ->and(entryState($page, 'stat_projects'))->toBe(2)
        ->and(entryState($page, 'stat_open_findings'))->toBe(voightTrans('fields.none'));
});

it('summarises the open findings of the latest runs across the projects of the team', function () {
    $team = Team::factory()->create();
    $environment = Environment::factory()->for(Project::factory()->for($team))->create();
    $oldRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);
    $latestRun = AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    AuditFinding::factory()->for($oldRun, 'auditRun')->for(Vulnerability::factory()->critical())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for(Vulnerability::factory()->medium())->create();

    $summary = $team->openFindingsSummary();

    expect($summary['count'])->toBe(1)
        ->and($summary['severity']->color())->toBe('warning');
});

it('reports no open findings for a team without projects', function () {
    expect(Team::factory()->create()->openFindingsSummary())->toBe(['count' => 0, 'severity' => null]);
});

it('titles the users relation manager Members', function () {
    $team = Team::factory()->create();

    expect(UsersRelationManager::getTitle($team, ViewTeam::class))->toBe('Members');
});
