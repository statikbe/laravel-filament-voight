<?php

namespace Statikbe\FilamentVoight;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

class FilamentVoightPlugin implements Plugin
{
    const string ID = 'filament-voight';

    /**
     * Display formats for the panel; the host registers them as Filament defaults, titles and strings read them directly.
     */
    const string DATE_FORMAT = 'd/m/Y';

    const string DATE_TIME_FORMAT = 'd/m/Y H:i';

    const string TIME_FORMAT = 'H:i';

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CustomerResource::class,
            TeamResource::class,
            ProjectResource::class,
            PackageResource::class,
            VulnerabilityResource::class,
            AuditRunResource::class,
            EnvironmentResource::class,
        ]);

        $panel->pages([
            ListEnvironments::class,
            McpAccess::class,
        ]);

        // Merged into whatever groups the host panel registers; ungrouped top-level items always sort first.
        $panel->navigationGroups([
            NavigationGroup::make(fn (): string => voightTrans('navigation.management')),
            NavigationGroup::make(fn (): string => voightTrans('navigation.settings')),
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
