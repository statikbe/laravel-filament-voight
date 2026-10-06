<?php

namespace Statikbe\FilamentVoight\Tests\Support;

use Filament\Panel;
use Filament\PanelProvider;
use Statikbe\FilamentVoight\FilamentVoightPlugin;
use Statikbe\FilamentVoight\Models\Team;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('tenant')
            ->tenant(Team::class)
            ->plugin(FilamentVoightPlugin::make());
    }
}
