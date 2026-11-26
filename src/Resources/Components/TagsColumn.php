<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * A record's tags as "Type: Tag" badges.
 */
class TagsColumn
{
    public static function make(): TextColumn
    {
        // Named after the relationship so Filament eager-loads the tags.
        return TextColumn::make('tags.name')
            ->label(voightTrans('models.tag.plural'))
            ->state(fn (Model $record): array => TagOptions::labels($record))
            ->badge()
            ->color('gray')
            ->toggleable(isToggledHiddenByDefault: true);
    }

    public static function entry(): TextEntry
    {
        return TextEntry::make('tags.name')
            ->label(voightTrans('models.tag.plural'))
            ->state(fn (Model $record): array => TagOptions::labels($record))
            ->badge()
            ->color('gray')
            ->placeholder('—');
    }
}
