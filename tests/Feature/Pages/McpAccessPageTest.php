<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Http\Middleware\EnsureMcpUserToken;
use Statikbe\FilamentVoight\Pages\McpAccess;

beforeEach(function () {
    $this->user = mcpUser();
    $this->actingAs($this->user);
});

it('creates a token with the MCP ability and shows it once', function () {
    Livewire::test(McpAccess::class)
        ->callAction('create_token', ['name' => 'Claude Code'])
        ->assertHasNoActionErrors()
        ->assertNotified(voightTrans('pages.mcp_access.actions.created'));

    $token = $this->user->tokens()->sole();

    expect($token->name)->toBe('Claude Code')
        ->and($token->abilities)->toBe([EnsureMcpUserToken::ABILITY]);
});

it('renders the token with the Claude Code setup command', function () {
    $html = view('filament-voight::mcp.token-created', ['token' => '1|secret', 'url' => url('mcp/voight')])->render();

    expect($html)->toContain('1|secret')
        ->toContain('claude mcp add --transport http voight ' . url('mcp/voight') . ' --header "Authorization: Bearer 1|secret"');
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
        ->assertActionHidden('create_token');
});

it('is not accessible when MCP is disabled', function () {
    config(['filament-voight.mcp.enabled' => false]);

    expect(McpAccess::canAccess())->toBeFalse();
});
