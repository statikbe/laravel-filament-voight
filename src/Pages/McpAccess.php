<?php

namespace Statikbe\FilamentVoight\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Support\McpClientSetup;

/**
 * Lets the current user connect AI clients to the remote MCP server: one card per
 * client. Every client signs in through OAuth, as the user who connects it.
 * Reached from the user menu (see FilamentVoightPlugin), not the navigation.
 */
class McpAccess extends Page
{
    protected static ?string $slug = 'mcp-access';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return FilamentVoight::config()->mcpEnabled() && auth()->check();
    }

    public static function getNavigationLabel(): string
    {
        return voightTrans('pages.mcp_access.title');
    }

    public function getTitle(): string | Htmlable
    {
        return voightTrans('pages.mcp_access.title');
    }

    public function getSubheading(): ?string
    {
        return voightTrans('pages.mcp_access.description');
    }

    public function content(Schema $schema): Schema
    {
        $setup = McpClientSetup::make();

        return $schema->components([
            Section::make(voightTrans('pages.mcp_access.clients.heading'))
                ->description(voightTrans('pages.mcp_access.clients.description'))
                ->schema([
                    View::make('filament-voight::mcp.clients')
                        ->viewData([
                            'clients' => $setup->oauthAvailable ? $setup->clients() : [],
                            'oauthAvailable' => $setup->oauthAvailable,
                        ]),
                ]),
        ]);
    }
}
