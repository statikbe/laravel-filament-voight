<?php

namespace Statikbe\FilamentVoight;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Livewire\Features\SupportTesting\Testable;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Statikbe\FilamentVoight\Commands\FilamentVoightCommand;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Testing\TestsFilamentVoight;

class FilamentVoightServiceProvider extends PackageServiceProvider
{
    public static string $viewNamespace = 'laravel-filament-voight';

    public function configurePackage(Package $package): void
    {
        $package->name(FilamentVoightPlugin::ID)
            ->hasRoutes($this->getRoutes())
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
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

    public function packageRegistered(): void {}

    public function packageBooted(): void
    {
        Relation::morphMap(FilamentVoight::config()->getMorphMap());

        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        FilamentIcon::register($this->getIcons());

        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/laravel-filament-voight/{$file->getFilename()}"),
                ], 'laravel-filament-voight-stubs');
            }
        }

        Testable::mixin(new TestsFilamentVoight);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('voight:run-osv-scan')->daily();
        });
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
            FilamentVoightCommand::class,
            Commands\SyncLockFileCommand::class,
            Commands\CreateProjectTokenCommand::class,
            Commands\RunOsvScanCommand::class,
        ];
    }

    /**
     * @return array<string>
     */
    protected function getIcons(): array
    {
        return [];
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
        ];
    }
}
