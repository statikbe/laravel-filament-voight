<?php

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
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
        ->assertDontSee(voightTrans('pages.mcp_access.clients.oauth_unavailable_heading'));
});

it('shows no client and explains why without OAuth', function () {
    Livewire::test(McpAccess::class)
        ->assertSee(voightTrans('pages.mcp_access.clients.oauth_unavailable_heading'))
        ->assertDontSee(voightTrans('pages.mcp_access.clients.claude.button'))
        ->assertDontSee(voightTrans('pages.mcp_access.clients.copy_command'))
        ->assertDontSee('cursor://', escape: false)
        ->assertDontSee('vscode:mcp', escape: false);
});

it('offers no personal tokens', function () {
    enableMcpOAuth();

    Livewire::test(McpAccess::class)
        ->assertDontSee('Bearer')
        ->assertDontSee('token');
});
