<?php

namespace Statikbe\FilamentVoight;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
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
            ProjectResource::class,
            PackageResource::class,
            VulnerabilityResource::class,
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
