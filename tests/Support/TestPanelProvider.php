<?php

namespace Statikbe\FilamentVoight\Tests\Support;

use Filament\Panel;
use Statikbe\FilamentVoight\FilamentVoightPanelProvider;

class TestPanelProvider extends FilamentVoightPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return parent::panel($panel)->default();
    }
}
