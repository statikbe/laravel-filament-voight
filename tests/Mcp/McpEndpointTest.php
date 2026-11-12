<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
use Statikbe\FilamentVoight\Tests\Support\User;

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

/**
 * Stands in for Passport's "api" guard, which this package does not install: a
 * bearer token of the form "oauth:<user id>:<scope>" resolves to that user holding a
 * Passport access token with that scope, as Passport's guard hands it over.
 */
beforeEach(function () {
    Auth::viaRequest('fake-passport', function (Request $request) {
        if (preg_match('/^oauth:(\d+):(.+)$/', (string) $request->bearerToken(), $matches) !== 1) {
            return null;
        }

        return User::query()->find($matches[1])?->withAccessToken(fakePassportAccessToken([$matches[2]]));
    });

    config(['auth.guards.api' => ['driver' => 'fake-passport']]);
});

function oauthToken(User $user, string $scope = EnsureMcpUserToken::OAUTH_SCOPE): string
{
    return "oauth:{$user->getKey()}:{$scope}";
}

it('accepts an OAuth token with the mcp:use scope', function () {
    callMcpEndpoint(oauthToken(mcpUser(Team::factory()->create())))
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list_projects');
});

it('rejects an OAuth token without the mcp:use scope', function () {
    callMcpEndpoint(oauthToken(mcpUser(), 'other'))->assertForbidden();
    callMcpEndpoint(oauthToken(mcpUser(), '*'))->assertForbidden();
});

it('rejects a personal access token, also one with the former voight:mcp ability', function () {
    $user = mcpUser(Team::factory()->create());

    callMcpEndpoint($user->createToken('claude', ['voight:mcp'])->plainTextToken)->assertUnauthorized();
    callMcpEndpoint($user->createToken('everything')->plainTextToken)->assertUnauthorized();
});

it('rejects a logged-in session without a token', function () {
    $this->actingAs(mcpUser(Team::factory()->create()), 'web');

    callMcpEndpoint(null)->assertUnauthorized();
});

it('rejects a project sync token', function () {
    $token = Project::factory()->create()->createToken(Project::DEFAULT_API_TOKEN_NAME)->plainTextToken;

    callMcpEndpoint($token)->assertUnauthorized();
});

/**
 * Runs the middleware alone for whatever the auth middleware resolved as user.
 */
function handleWithToken(object $user, object $token): int
{
    $request = Request::create('/mcp/voight', 'POST');
    $request->setUserResolver(fn () => $user->withAccessToken($token));

    return (new EnsureMcpUserToken)->handle($request, fn () => response('passed'))->getStatusCode();
}

it('turns away a Sanctum token that another guard let through', function () {
    $user = mcpUser();

    expect(handleWithToken($user, $user->createToken('claude', ['voight:mcp'])->accessToken))->toBe(403);
});

it('rejects a project resolved through Passport, even with the mcp:use scope', function () {
    expect(handleWithToken(Project::factory()->create(), fakePassportAccessToken([EnsureMcpUserToken::OAUTH_SCOPE])))->toBe(403);
});

it('rejects requests without a token', function () {
    callMcpEndpoint(null)->assertUnauthorized();
});

it('registers the local and the web server when enabled', function () {
    expect(Mcp::getLocalServer('voight'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/voight'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/voight')->gatherMiddleware())->toContain('auth:api', EnsureMcpUserToken::class);
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
