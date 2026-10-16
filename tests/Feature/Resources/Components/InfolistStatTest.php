<?php

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Statikbe\FilamentVoight\Resources\Components\InfolistStat;

it('makes plain stat values large and semi-bold', function () {
    $entry = TextEntry::make('plain');
    InfolistStat::make($entry);

    expect($entry->getSize(null))->toBe(TextSize::Large)
        ->and($entry->getWeight(null))->toBe(FontWeight::SemiBold);
});

it('leaves badge stats alone', function () {
    $entry = TextEntry::make('badge')->badge();
    InfolistStat::make($entry);

    expect($entry->getWeight(null))->not->toBe(FontWeight::SemiBold)
        ->and($entry->getSize(null))->not->toBe(TextSize::Large);
});

it('sizes the strip columns to the tile count', function (int $tiles, int $md, int $xl) {
    $strip = InfolistStat::strip(array_fill(0, $tiles, Section::make()));

    expect($strip->getColumns())->toBe(['default' => 2, 'md' => $md, 'xl' => $xl]);
})->with([
    '3 tiles' => [3, 3, 3],
    '4 tiles' => [4, 3, 4],
]);
