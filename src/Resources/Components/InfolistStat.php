<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * A compact label + value tile for the stat strip at the top of a view page.
 */
class InfolistStat
{
    /**
     * Wrap an already configured entry in a stat tile.
     */
    public static function make(TextEntry $entry): Section
    {
        // Badges keep their own size; plain values need to stand out from the label.
        if (! $entry->isBadge()) {
            $entry->size(TextSize::Large)->weight(FontWeight::SemiBold);
        }

        return Section::make()
            ->compact()
            ->components([$entry]);
    }

    /**
     * The open findings tile for any model exposing openFindingsSummary(); zero reads "None" instead of a badge.
     */
    public static function openFindings(string $label): Section
    {
        return self::make(
            TextEntry::make('stat_open_findings')
                ->label($label)
                ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: voightTrans('hints.open_findings'))
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->state(fn (Model $record): string => $record->openFindingsSummary()['count'] === 0
                    ? voightTrans('fields.none')
                    : (string) $record->openFindingsSummary()['count'])
                ->badge()
                ->color(fn (Model $record): string => $record->openFindingsSummary()['severity']?->color() ?? 'gray'),
        );
    }

    /**
     * The responsive grid holding the stat tiles; columns follow the tile count so a short strip fills the row.
     *
     * @param  array<int, Section>  $stats
     */
    public static function strip(array $stats): Grid
    {
        $count = count($stats);

        return Grid::make(['default' => 2, 'md' => min($count, 3), 'xl' => min($count, 4)])
            ->columnSpanFull()
            ->components($stats);
    }
}
