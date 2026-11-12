<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Http\Middleware\EnsureMcpUserToken;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Support\McpClient;
use Statikbe\FilamentVoight\Support\McpClientSetup;

beforeEach(function () {
    $this->user = mcpUser();
    $this->actingAs($this->user);
});

/**
 * Registers the route Mcp::oauthRoutes() would, which flips mcpOAuthAvailable().
 */
function enableMcpOAuth(): void
{
    Route::get('.well-known/oauth-protected-resource', fn () => [])->name('mcp.oauth.protected-resource');
    Route::getRoutes()->refreshNameLookups();
}

/**
 * @return array<string, McpClient>
 */
function mcpClientsByKey(): array
{
    return collect(McpClientSetup::make()->clients())->keyBy('key')->all();
}

it('creates a token with the MCP ability and shows it once', function () {
    Livewire::test(McpAccess::class)
        ->callAction(TestAction::make('create_token')->table(), ['name' => 'Claude Code'])
        ->assertHasNoActionErrors()
        ->assertNotified(voightTrans('pages.mcp_access.actions.created'));

    $token = $this->user->tokens()->sole();

    expect($token->name)->toBe('Claude Code')
        ->and($token->abilities)->toBe([EnsureMcpUserToken::ABILITY]);
});

it('renders the token with the Claude Code setup command', function () {
    $html = view('filament-voight::mcp.token-created', [
        'token' => '1|secret',
        'command' => McpClientSetup::make()->claudeCodeCommand('1|secret'),
    ])->render();

    expect($html)->toContain('1|secret')
        ->toContain(e('claude mcp add --transport http voight ' . url('mcp/voight') . ' --header "Authorization: Bearer 1|secret"'));
});

it('lists only the current user\'s MCP tokens', function () {
    $mine = $this->user->createToken('mine', [EnsureMcpUserToken::ABILITY]);
    $this->user->createToken('not for mcp', ['other']);
    mcpUser()->createToken('someone else', [EnsureMcpUserToken::ABILITY]);

    Livewire::test(McpAccess::class)
        ->assertCanSeeTableRecords([$mine->accessToken])
        ->assertCountTableRecords(1);
});

it('revokes a token', function () {
    $token = $this->user->createToken('mine', [EnsureMcpUserToken::ABILITY]);

    Livewire::test(McpAccess::class)->callTableAction('revoke', $token->accessToken);

    expect($this->user->tokens()->count())->toBe(0);
});

it('explains the missing trait when the user model cannot have tokens', function () {
    config(['filament-voight.models.user' => User::class]);

    Livewire::test(McpAccess::class)
        ->assertSee('HasApiTokens')
        ->assertDontSee(voightTrans('pages.mcp_access.actions.create'));
});

it('is not accessible when MCP is disabled', function () {
    config(['filament-voight.mcp.enabled' => false]);

    expect(McpAccess::canAccess())->toBeFalse();
});

it('is reached from the user menu instead of the navigation', function () {
    $item = filament()->getCurrentOrDefaultPanel()->getUserMenuItems()['voight-mcp-access'] ?? null;

    expect(McpAccess::shouldRegisterNavigation())->toBeFalse()
        ->and($item)->not->toBeNull()
        ->and($item->getUrl())->toBe(McpAccess::getUrl())
        ->and($item->getLabel())->toBe(voightTrans('pages.mcp_access.title'));

    config(['filament-voight.mcp.enabled' => false]);

    expect(filament()->getCurrentOrDefaultPanel()->getUserMenuItems())->not->toHaveKey('voight-mcp-access');
});

it('reports OAuth as available once the OAuth routes are registered', function () {
    expect(FilamentVoight::config()->mcpOAuthAvailable())->toBeFalse();

    enableMcpOAuth();

    expect(FilamentVoight::config()->mcpOAuthAvailable())->toBeTrue();
});

it('offers one-click setup for every client when OAuth is available', function () {
    enableMcpOAuth();
    $url = url('mcp/voight');
    $clients = mcpClientsByKey();

    expect(array_keys($clients))->toBe(['claude', 'chatgpt', 'cursor', 'vscode', 'claude-code', 'codex'])
        ->and($clients['claude']->buttonUrl)->toBe('https://claude.ai/customize/connectors')
        ->and($clients['claude']->copies)->toBe($url)
        ->and($clients['chatgpt']->buttonUrl)->toBe('https://chatgpt.com/plugins')
        ->and($clients['chatgpt']->copies)->toBe($url)
        ->and($clients['cursor']->buttonUrl)->toBe('cursor://anysphere.cursor-deeplink/mcp/install?name=voight&config=' . rawurlencode(base64_encode('{"url":"' . $url . '"}')))
        ->and($clients['vscode']->buttonUrl)->toBe('vscode:mcp/install?' . rawurlencode('{"name":"voight","type":"http","url":"' . $url . '"}'))
        ->and($clients['claude-code']->copies)->toBe("claude mcp add --transport http voight {$url}")
        ->and($clients['codex']->copies)->toBe("codex mcp add voight --url {$url}");

    Livewire::test(McpAccess::class)
        ->assertSee(voightTrans('pages.mcp_access.clients.claude.button'))
        ->assertSee(voightTrans('pages.mcp_access.clients.chatgpt.button'))
        ->assertSee('cursor://anysphere.cursor-deeplink/mcp/install', escape: false)
        ->assertSee('vscode:mcp/install?', escape: false)
        ->assertDontSee(voightTrans('pages.mcp_access.clients.oauth_unavailable_heading'))
        ->assertDontSee(McpClientSetup::TOKEN_PLACEHOLDER);
});

it('hides the OAuth-only clients and shows token instructions without OAuth', function () {
    $url = url('mcp/voight');
    $clients = mcpClientsByKey();

    expect(array_keys($clients))->toBe(['cursor', 'vscode', 'claude-code', 'codex'])
        ->and(collect($clients)->pluck('buttonUrl')->filter()->all())->toBe([])
        ->and($clients['cursor']->snippet)->toContain($url, 'Bearer <your-token>')
        ->and($clients['vscode']->snippet)->toContain($url, 'Bearer <your-token>')
        ->and($clients['claude-code']->copies)->toBe("claude mcp add --transport http voight {$url} --header \"Authorization: Bearer <your-token>\"")
        ->and($clients['codex']->copies)->toBe("codex mcp add voight --url {$url} --bearer-token-env-var VOIGHT_MCP_TOKEN");

    Livewire::test(McpAccess::class)
        ->assertSee(voightTrans('pages.mcp_access.clients.oauth_unavailable_heading'))
        ->assertDontSee(voightTrans('pages.mcp_access.clients.claude.button'))
        ->assertDontSee(voightTrans('pages.mcp_access.clients.chatgpt.button'))
        ->assertDontSee('cursor://', escape: false)
        ->assertDontSee('vscode:mcp', escape: false)
        ->assertSee(McpClientSetup::TOKEN_PLACEHOLDER);
});

it('never puts a real token in a client card', function () {
    $token = $this->user->createToken('mine', [EnsureMcpUserToken::ABILITY])->plainTextToken;

    Livewire::test(McpAccess::class)->assertDontSee($token);

    enableMcpOAuth();

    Livewire::test(McpAccess::class)->assertDontSee($token);
});
