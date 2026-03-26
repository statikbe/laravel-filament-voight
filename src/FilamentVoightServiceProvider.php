<?php

namespace Statikbe\FilamentVoight;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Livewire\Features\SupportTesting\Testable;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Statikbe\FilamentVoight\Commands\FilamentVoightCommand;
use Statikbe\FilamentVoight\Testing\TestsFilamentVoight;

class FilamentVoightServiceProvider extends PackageServiceProvider
{
    public static string $viewNamespace = 'laravel-filament-voight';

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package->name(FilamentVoightPlugin::ID)
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

        $package->hasRoutes($this->getRoutes());
    }

    public function packageRegistered(): void {}

    public function packageBooted(): void
    {
        // Asset Registration
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        // Icon Registration
        FilamentIcon::register($this->getIcons());

        // Handle Stubs
        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/laravel-filament-voight/{$file->getFilename()}"),
                ], 'laravel-filament-voight-stubs');
            }
        }

        // Testing
        Testable::mixin(new TestsFilamentVoight);
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
        return [
            // AlpineComponent::make('laravel-filament-voight', __DIR__ . '/../resources/dist/components/laravel-filament-voight.js'),
            // Css::make('laravel-filament-voight-styles', __DIR__ . '/../resources/dist/laravel-filament-voight.css'),
            // Js::make('laravel-filament-voight-scripts', __DIR__ . '/../resources/dist/laravel-filament-voight.js'),
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            FilamentVoightCommand::class,
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
        return [];
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
