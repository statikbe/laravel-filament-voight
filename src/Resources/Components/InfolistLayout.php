<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;

/**
 * Main column (two thirds) with a sidebar (one third) from the lg breakpoint up.
 */
class InfolistLayout
{
    /**
     * @param  array<int, Component>  $main
     * @param  array<int, Component>  $sidebar
     */
    public static function withSidebar(array $main, array $sidebar): Grid
    {
        return Grid::make(['default' => 1, 'lg' => 3])
            ->columnSpanFull()
            ->components([
                Group::make($main)->columnSpan(['lg' => 2]),
                Group::make($sidebar)->columnSpan(['lg' => 1]),
            ]);
    }
}
