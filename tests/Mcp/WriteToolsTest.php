<?php

use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken;
use Statikbe\FilamentVoight\Mcp\Tools\CreateProjectTokenTool;
use Statikbe\FilamentVoight\Mcp\Tools\CreateProjectTool;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Mcp\VoightServer;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;

beforeEach(function () {
    $this->myTeam = Team::factory()->create(['name' => 'Mine']);
    Team::factory()->create(['name' => 'Others']);
    Customer::factory()->create(['name' => 'Acme']);
    $this->user = mcpUser($this->myTeam);
});

describe('create_project', function () {
    it('creates a project in one of the caller\'s teams', function () {
        $result = structuredContent(VoightServer::actingAs($this->user)->tool(CreateProjectTool::class, [
            'project_code' => 'NEW',
            'name' => 'New project',
            'repo_url' => 'https://github.com/statikbe/new',
            'team' => 'Mine',
            'customer' => 'Acme',
        ]));

        expect($result['project'])->toBe(['code' => 'NEW', 'name' => 'New project', 'team' => 'Mine', 'customer' => 'Acme'])
            ->and($result['next_step'])->toContain('create_project_token')
            ->and(Project::query()->where('project_code', 'NEW')->value('repo_url'))->toBe('https://github.com/statikbe/new');
    });

    it('only lets remote users pick a team they belong to', function () {
        VoightServer::actingAs($this->user)->tool(CreateProjectTool::class, ['project_code' => 'NEW', 'name' => 'New', 'team' => 'Others'])
            ->assertHasErrors(['Unknown team `Others`. Valid teams you can use: Mine.']);
        VoightServer::actingAs($this->user)->tool(CreateProjectTool::class, ['project_code' => 'NEW', 'name' => 'New'])
            ->assertHasErrors(['team']);

        expect(Project::query()->where('project_code', 'NEW')->exists())->toBeFalse();
    });

    it('lets the local server create a project without a team', function () {
        VoightLocalServer::tool(CreateProjectTool::class, ['project_code' => 'NEW', 'name' => 'New'])->assertHasNoErrors();

        expect(Project::query()->where('project_code', 'NEW')->value('team_id'))->toBeNull();
    });

    it('lists the valid customers for an unknown one', function () {
        VoightServer::actingAs($this->user)->tool(CreateProjectTool::class, ['project_code' => 'NEW', 'name' => 'New', 'team' => 'Mine', 'customer' => 'Nope'])
            ->assertHasErrors(['Unknown customer `Nope`. Valid customers: Acme.']);
    });

    it('fails on a taken code without touching the existing project', function () {
        $existing = Project::factory()->create(['project_code' => 'TAKEN', 'name' => 'Original', 'team_id' => null]);

        VoightServer::actingAs($this->user)->tool(CreateProjectTool::class, ['project_code' => 'TAKEN', 'name' => 'Changed', 'team' => 'Mine'])
            ->assertHasErrors(['A project with code `TAKEN` already exists.'])
            ->assertDontSee('Original');

        expect($existing->fresh()->name)->toBe('Original')
            ->and($existing->fresh()->team_id)->toBeNull();
    });
});

describe('create_project_token', function () {
    it('issues a working project token and keeps the existing ones', function () {
        $project = Project::factory()->for($this->myTeam)->create(['project_code' => 'MINE']);
        $existing = $project->createToken('existing');

        $result = structuredContent(VoightServer::actingAs($this->user)->tool(CreateProjectTokenTool::class, ['project_code' => 'MINE']));

        expect($result['token_name'])->toBe(Project::DEFAULT_API_TOKEN_NAME)
            ->and($result['uploader_variables']['VOIGHT_API_TOKEN'])->toBe($result['token'])
            ->and($result['uploader_variables']['PROJECT_CODE'])->toBe('MINE')
            ->and($result['sync_endpoint'])->toEndWith('/api/voight/lock-file')
            ->and($result['instructions'])->toContain('Never commit')
            ->and(PersonalAccessToken::findToken($result['token'])->tokenable->is($project))->toBeTrue()
            ->and(PersonalAccessToken::findToken($existing->plainTextToken))->not->toBeNull();

        $request = request()->duplicate();
        $request->headers->set('Authorization', 'Bearer ' . $result['token']);
        $response = (new AuthenticateProjectToken)->handle($request, fn ($request) => response()->noContent());

        expect($response->getStatusCode())->toBe(204)
            ->and($request->attributes->get('voight_project')->is($project))->toBeTrue();
    });

    it('uses the given token name', function () {
        Project::factory()->for($this->myTeam)->create(['project_code' => 'MINE']);

        expect(structuredContent(VoightServer::actingAs($this->user)->tool(CreateProjectTokenTool::class, ['project_code' => 'MINE', 'name' => 'github-actions']))['token_name'])
            ->toBe('github-actions');
    });
});

/**
 * Names in a tools/list or prompts/list response. The package's assertRegistered() instantiates
 * classes without the container, which tools with constructor dependencies do not support.
 *
 * @return array<int, string>
 */
function registeredNames(TestListResponse $response): array
{
    return array_column((fn (): array => $this->items)->call($response), 'name');
}

it('hides the write tools and the onboarding prompt when write tools are off', function () {
    config(['filament-voight.mcp.write_tools' => false]);

    expect(registeredNames(VoightServer::actingAs($this->user)->tools()))->not->toContain('create_project', 'create_project_token')
        ->and(registeredNames(VoightLocalServer::tools()))->not->toContain('create_project', 'create_project_token')
        ->and(registeredNames(VoightLocalServer::prompts()))->not->toContain('onboard_project');
});

it('registers every tool and prompt by default', function () {
    expect(registeredNames(VoightLocalServer::tools()))->toBe([
        'list_projects', 'get_project_vulnerabilities', 'get_upgrade_plan', 'find_package_usage',
        'get_vulnerability', 'get_statistics', 'create_project', 'create_project_token',
    ])->and(registeredNames(VoightLocalServer::prompts()))->toBe(['triage_project', 'security_digest', 'onboard_project']);
});
