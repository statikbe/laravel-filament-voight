<?php

namespace Statikbe\FilamentVoight;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Resources\AuditFindingResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TagResource;
use Statikbe\FilamentVoight\Resources\TagTypeResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

class FilamentVoightPlugin implements Plugin
{
    const string ID = 'filament-voight';

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CustomerResource::class,
            TeamResource::class,
            TagTypeResource::class,
            TagResource::class,
            ProjectResource::class,
            PackageResource::class,
            VulnerabilityResource::class,
            AuditFindingResource::class,
            AuditRunResource::class,
            EnvironmentResource::class,
        ]);

        // Unregistered host groups sort after these; ungrouped items and the blank group stay first.
        $panel->navigationGroups([
            NavigationGroup::make(fn (): string => voightTrans('navigation.settings')),
        ]);

        $panel->pages([
            ListEnvironments::class,
            McpAccess::class,
        ]);

        $panel->userMenuItems([
            'voight-mcp-access' => Action::make('voight-mcp-access')
                ->label(fn (): string => voightTrans('pages.mcp_access.title'))
                ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::UserMenuMcpAccess->resolve())
                ->url(fn (): string => McpAccess::getUrl(panel: $panel->getId()))
                ->visible(fn (): bool => McpAccess::canAccess()),
        ]);

        $panel->widgets([
            Widgets\SeverityOverviewWidget::class,
            Widgets\AuditRunStatusWidget::class,
            Widgets\ActiveFindingsWidget::class,
            Widgets\MostVulnerableProjectsWidget::class,
            Widgets\RecentAuditRunsWidget::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
