<?php

namespace Statikbe\FilamentVoight\Resources\HostingResource\Schemas;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Models\HostingTag;

class HostingTableSchema
{
    public static function configure(Table $table): Table
    {
        // The name is a translatable JSON column, so search and sort read the current locale.
        $name = fn (Builder $query): string => $query->getQuery()->getGrammar()->wrap('name->' . HostingTag::getLocale());

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.hosting.fields.name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw('LOWER(' . $name($query) . ') LIKE ?', ['%' . mb_strtolower($search) . '%']))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw($name($query) . ' ' . ($direction === 'desc' ? 'desc' : 'asc'))),
                TextColumn::make('environments_count')
                    ->label(voightTrans('models.environment.plural'))
                    ->counts('environments')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw($name($query)))
            ->recordActions([
                EditAction::make(),
                // Removes the tag from its environments; the environments stay.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
