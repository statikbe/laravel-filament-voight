<?php

use Filament\Schemas\Components\Group;
use Statikbe\FilamentVoight\Resources\Components\InfolistLayout;

it('lays out a main column and a sidebar', function () {
    $layout = InfolistLayout::withSidebar([Group::make()], [Group::make()]);
    [$main, $sidebar] = $layout->getDefaultChildComponents();

    expect($layout->getColumns())->toBe(['default' => 1, 'lg' => 3])
        ->and($main->getColumnSpan('lg'))->toBe(2)
        ->and($sidebar->getColumnSpan('lg'))->toBe(1);
});
