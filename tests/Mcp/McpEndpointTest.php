<?php

use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Statikbe\FilamentVoight\FilamentVoightServiceProvider;
use Statikbe\FilamentVoight\Http\Middleware\EnsureMcpUserToken;
use Statikbe\FilamentVoight\Mcp\Prompts\OnboardProjectPrompt;
use Statikbe\FilamentVoight\Mcp\Prompts\SecurityDigestPrompt;
use Statikbe\FilamentVoight\Mcp\Prompts\TriageProjectPrompt;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;

/**
 * A JSON-RPC call to the remote server.
 */
function callMcpEndpoint(?string $token, string $method = 'tools/list'): TestResponse
{
    return test()->postJson('/mcp/voight', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method], array_filter([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => $token === null ? null : "Bearer {$token}",
    ]));
}

it('accepts a user token with the voight:mcp ability', function () {
    $token = mcpUser(Team::factory()->create())->createToken('claude', [EnsureMcpUserToken::ABILITY])->plainTextToken;

    callMcpEndpoint($token)->assertOk()->assertJsonPath('result.tools.0.name', 'list_projects');
});

it('rejects a user token without the voight:mcp ability', function () {
    $token = mcpUser()->createToken('other', ['something-else'])->plainTextToken;

    callMcpEndpoint($token)->assertForbidden();
});

it('rejects a project sync token', function () {
    $token = Project::factory()->create()->createToken(Project::DEFAULT_API_TOKEN_NAME)->plainTextToken;

    callMcpEndpoint($token)->assertForbidden();
});

it('rejects requests without a token', function () {
    callMcpEndpoint(null)->assertUnauthorized();
});

it('registers the local and the web server when enabled', function () {
    expect(Mcp::getLocalServer('voight'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/voight'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/voight')->gatherMiddleware())->toContain('auth:sanctum', EnsureMcpUserToken::class);
});

it('registers nothing when disabled', function () {
    config(['filament-voight.mcp.enabled' => false]);
    $this->app->forgetInstance(Registrar::class);
    Mcp::clearResolvedInstances();

    $this->app->getProvider(FilamentVoightServiceProvider::class)->registerMcpServers();

    expect(Mcp::getLocalServer('voight'))->toBeNull()
        ->and(Mcp::servers())->toBe([]);
});

it('serves the prompts', function () {
    VoightLocalServer::prompt(TriageProjectPrompt::class, ['project_code' => 'APP'])
        ->assertHasNoErrors()->assertSee(['get_project_vulnerabilities', 'get_upgrade_plan', '`APP`']);
    VoightLocalServer::prompt(SecurityDigestPrompt::class, ['team' => 'Web'])
        ->assertHasNoErrors()->assertSee(['get_statistics', 'scanned_before', 'team: "Web"']);
    VoightLocalServer::prompt(OnboardProjectPrompt::class)
        ->assertHasNoErrors()->assertSee(['create_project', 'create_project_token', 'VOIGHT_API_BASE_URL', 'secret']);
});
