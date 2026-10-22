<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Testing\TestResponse;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\Tools\CreateProjectTokenTool;
use Statikbe\FilamentVoight\Mcp\Tools\CreateProjectTool;
use Statikbe\FilamentVoight\Mcp\Tools\FindPackageUsageTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetProjectVulnerabilitiesTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetStatisticsTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetUpgradePlanTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetVulnerabilityTool;
use Statikbe\FilamentVoight\Mcp\Tools\ListProjectsTool;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Mcp\VoightServer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Tests\Support\ConfigurableProjectPolicy;

beforeEach(function () {
    ConfigurableProjectPolicy::$abilities = [];
    ConfigurableProjectPolicy::$deniedProjects = [];

    $this->myTeam = Team::factory()->create(['name' => 'Mine']);
    $this->otherTeam = Team::factory()->create(['name' => 'Others']);
    $this->user = mcpUser($this->myTeam);
    $this->vulnerability = Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-aaaa', 'aliases' => ['CVE-2026-1']]);

    $this->mine = Project::factory()->for($this->myTeam)->create(['project_code' => 'MINE']);
    $this->theirs = Project::factory()->for($this->otherTeam)->create(['project_code' => 'THEIRS']);
    $this->teamless = Project::factory()->create(['project_code' => 'TEAMLESS', 'team_id' => null]);

    foreach ([$this->mine, $this->theirs, $this->teamless] as $project) {
        mcpFinding(Environment::factory()->for($project)->create(['name' => 'production']), $this->vulnerability);
    }
});

/**
 * @return array<int, string>
 */
function listedCodes(TestResponse $response): array
{
    return collect(structuredContent($response)['projects'])->pluck('code')->sort()->values()->all();
}

it('shows a team member only the projects of their teams', function () {
    expect(listedCodes(VoightServer::actingAs($this->user)->tool(ListProjectsTool::class)))->toBe(['MINE']);
});

it('reports an invisible project exactly like an unknown one', function (string $tool) {
    $invisible = VoightServer::actingAs($this->user)->tool($tool, ['project_code' => 'THEIRS']);
    $unknown = VoightServer::actingAs($this->user)->tool($tool, ['project_code' => 'NOPE']);

    $invisible->assertHasErrors(['Project `THEIRS` not found.']);
    $unknown->assertHasErrors(['Project `NOPE` not found.']);
})->with([GetProjectVulnerabilitiesTool::class, GetUpgradePlanTool::class, CreateProjectTokenTool::class]);

it('hides teamless projects from non-admins', function () {
    VoightServer::actingAs($this->user)->tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'TEAMLESS'])
        ->assertHasErrors(['not found']);
});

it('only counts visible projects in cross-project tools', function () {
    VoightServer::actingAs($this->user)->tool(FindPackageUsageTool::class, ['package' => 'lodash'])
        ->assertSee('MINE')->assertDontSee(['THEIRS', 'TEAMLESS']);

    VoightServer::actingAs($this->user)->tool(GetVulnerabilityTool::class, ['id' => 'GHSA-aaaa'])
        ->assertSee('MINE')->assertDontSee(['THEIRS', 'TEAMLESS']);

    VoightServer::actingAs($this->user)->tool(GetStatisticsTool::class, ['group_by' => 'vulnerability'])
        ->assertStructuredContent(fn ($json) => $json->where('groups.0.affected_projects', 1)->etc());
});

it('lets the admin callback see everything', function () {
    FilamentVoight::resolveMcpAdminUsing(fn ($user): bool => $user->is($this->user));

    expect(listedCodes(VoightServer::actingAs($this->user)->tool(ListProjectsTool::class)))->toBe(['MINE', 'TEAMLESS', 'THEIRS']);
});

it('lets the local server see everything', function () {
    expect(listedCodes(VoightLocalServer::tool(ListProjectsTool::class)))->toBe(['MINE', 'TEAMLESS', 'THEIRS']);
});

it('errors on the remote server without a user', function () {
    VoightServer::tool(ListProjectsTool::class)->assertHasErrors(['Unauthenticated']);
    VoightServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'MINE'])->assertHasErrors(['not found']);
});

it('replaces the team rule with the host visibility resolver', function () {
    FilamentVoight::resolveMcpVisibleProjectsUsing(fn (Builder $query): Builder => $query->where('project_code', 'THEIRS'));

    expect(listedCodes(VoightServer::actingAs($this->user)->tool(ListProjectsTool::class)))->toBe(['THEIRS']);
});

it('applies the admin callback before the host visibility resolver', function () {
    FilamentVoight::resolveMcpVisibleProjectsUsing(fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    FilamentVoight::resolveMcpAdminUsing(fn (): bool => true);

    expect(listedCodes(VoightServer::actingAs($this->user)->tool(ListProjectsTool::class)))->toBe(['MINE', 'TEAMLESS', 'THEIRS']);
});

describe('host policies', function () {
    beforeEach(fn () => Gate::policy(Project::class, ConfigurableProjectPolicy::class));

    it('lets tools work when the policy allows', function () {
        VoightServer::actingAs($this->user)->tool(ListProjectsTool::class)->assertHasNoErrors();
    });

    it('blocks list tools when viewAny is denied', function (string $tool, array $arguments) {
        ConfigurableProjectPolicy::$abilities['viewAny'] = false;

        VoightServer::actingAs($this->user)->tool($tool, $arguments)->assertHasErrors(['not allowed']);
    })->with([
        [ListProjectsTool::class, []],
        [GetStatisticsTool::class, ['group_by' => 'project']],
        [FindPackageUsageTool::class, ['package' => 'lodash']],
        [GetVulnerabilityTool::class, ['id' => 'GHSA-aaaa']],
    ]);

    it('reports a project whose view is denied as not found', function () {
        ConfigurableProjectPolicy::$deniedProjects = ['MINE'];

        VoightServer::actingAs($this->user)->tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'MINE'])
            ->assertHasErrors(['Project `MINE` not found.']);
    });

    it('blocks the write tools when create or update is denied', function () {
        ConfigurableProjectPolicy::$abilities = ['create' => false, 'update' => false];

        VoightServer::actingAs($this->user)->tool(CreateProjectTool::class, ['project_code' => 'NEW', 'name' => 'New', 'team' => 'Mine'])
            ->assertHasErrors(['not allowed']);
        VoightServer::actingAs($this->user)->tool(CreateProjectTokenTool::class, ['project_code' => 'MINE'])
            ->assertHasErrors(['not found']);
    });

    it('never reveals projects outside the visibility scope', function () {
        expect(listedCodes(VoightServer::actingAs($this->user)->tool(ListProjectsTool::class)))->toBe(['MINE']);
    });

    it('is ignored by the local server', function () {
        ConfigurableProjectPolicy::$abilities = ['viewAny' => false, 'view' => false];

        VoightLocalServer::tool(ListProjectsTool::class)->assertHasNoErrors();
        VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'MINE'])->assertHasNoErrors();
    });
});
