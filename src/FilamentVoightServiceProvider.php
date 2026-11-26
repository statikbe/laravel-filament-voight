<?php

namespace Statikbe\FilamentVoight;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Facades\Mcp;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\FilamentVoight as FilamentVoightManager;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Mcp\VoightServer;

class FilamentVoightServiceProvider extends PackageServiceProvider
{
    public static string $viewNamespace = 'filament-voight';

    public function configurePackage(Package $package): void
    {
        $package->name(FilamentVoightPlugin::ID)
            ->hasRoutes($this->getRoutes())
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->publish('scripts')
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('statikbe/laravel-filament-voight');
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../database/migrations'))) {
            $package->hasMigrations($this->getMigrations());
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        // A singleton, so the MCP visibility hooks registered by the host survive.
        $this->app->singleton(FilamentVoightManager::class);

        // Registered before app providers run, so a host's own FilamentIcon::register() always wins over config.
        FilamentIcon::register($this->getIcons());
    }

    public function packageBooted(): void
    {
        Relation::morphMap(FilamentVoight::config()->getMorphMap());
        Relation::morphMap(['voight-user' => FilamentVoight::config()->getUserModel()]);

        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        $this->registerMcpServers();

        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/filament-voight/{$file->getFilename()}"),
                ], 'filament-voight-stubs');
            }

            $this->publishes([
                __DIR__ . '/../resources/scripts/voight.sh' => public_path('scripts/voight.sh'),
            ], 'filament-voight-scripts');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Registered before the scan schedule: digests and the reaper are
            // independent of the scanner cron and must keep running when it is disabled.
            $schedule->command('voight:send-alert-digests')->hourly();
            $schedule->command('voight:reap-stranded-scans')->hourly()->withoutOverlapping();
            $schedule->command('voight:run-tag-rules')->daily()->withoutOverlapping();

            $cron = FilamentVoight::config()->getScannerNightlyCron();

            if ($cron === '') {
                return;
            }

            $schedule->command('voight:run-osv-scan --nightly')->cron($cron)->withoutOverlapping();
        });
    }

    /**
     * The local server runs as the machine's operator; the web server requires an authenticated user.
     */
    public function registerMcpServers(): void
    {
        $config = FilamentVoight::config();

        if (! $config->mcpEnabled()) {
            return;
        }

        Mcp::local('voight', VoightLocalServer::class);
        Mcp::web($config->getMcpPath(), VoightServer::class)->middleware($config->getMcpMiddleware());
    }

    protected function getAssetPackageName(): ?string
    {
        return 'statikbe/laravel-filament-voight';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            Commands\SyncLockFileCommand::class,
            Commands\CreateProjectTokenCommand::class,
            Commands\RunOsvScanCommand::class,
            Commands\ReapStrandedScansCommand::class,
            Commands\SendAlertDigestsCommand::class,
            Commands\ReprocessLockfilesCommand::class,
            Commands\FetchFaviconsCommand::class,
            Commands\ListIconsCommand::class,
            Commands\RunTagRulesCommand::class,
            Commands\RebuildVersionKeysCommand::class,
        ];
    }

    /**
     * @return array<string, string|\BackedEnum|Htmlable>
     */
    protected function getIcons(): array
    {
        return VoightIcon::configOverrides();
    }

    /**
     * @return array<string>
     */
    protected function getRoutes(): array
    {
        return [
            'api',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return [
            'create_voight_customers_table',
            'create_voight_teams_table',
            'create_voight_team_user_table',
            'create_voight_projects_table',
            'create_voight_environments_table',
            'create_voight_packages_table',
            'create_voight_environment_packages_table',
            'create_voight_dependency_syncs_table',
            'create_voight_vulnerabilities_table',
            'create_voight_vulnerable_package_ranges_table',
            'create_voight_audit_runs_table',
            'create_voight_audit_findings_table',
            'create_voight_alert_settings_table',
            'add_scan_nightly_to_voight_environments_table',
            'add_trigger_to_voight_audit_runs_table',
            'create_voight_alert_recipients_table',
            'add_slack_channel_to_voight_alert_settings_table',
            'add_last_sent_at_to_voight_alert_settings_table',
            'create_voight_alert_notification_logs_table',
            'add_warnings_to_voight_dependency_syncs_table',
            'add_error_message_to_voight_audit_runs_table',
            'create_voight_environment_package_dependencies_table',
            'drop_parent_package_id_from_voight_environment_packages_table',
            'create_voight_nightly_sweeps_table',
            'create_voight_osv_scans_table',
            'create_voight_environment_system_details_table',
            'add_avatar_path_to_voight_teams_table',
            'add_url_to_voight_environments_table',
            'add_favicon_to_voight_projects_table',
            'add_package_types_to_voight_alert_settings_table',
            'add_version_sort_to_voight_environment_packages_table',
            'add_installed_version_sort_to_voight_audit_findings_table',
            'add_php_version_sort_to_voight_environment_system_details_table',
            'create_voight_tag_types_table',
            'create_voight_tags_table',
            'create_voight_taggables_table',
            'create_voight_tag_rules_table',
            'create_voight_tag_rule_matches_table',
            'add_is_manual_to_voight_taggables_table',
        ];
    }
}
