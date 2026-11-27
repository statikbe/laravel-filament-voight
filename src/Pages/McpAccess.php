<?php

namespace Statikbe\FilamentVoight\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Support\McpClientSetup;

/**
 * Lets the current user connect AI clients to the remote MCP server: one card per
 * client. Every client signs in through OAuth, as the user who connects it.
 * Listed in the Settings navigation group.
 */
class McpAccess extends Page
{
    protected static ?string $slug = 'mcp-access';

    protected static ?int $navigationSort = 4;

    public static function getNavigationIcon(): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::NavigationMcpAccess->resolve();
    }

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.settings');
    }

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
