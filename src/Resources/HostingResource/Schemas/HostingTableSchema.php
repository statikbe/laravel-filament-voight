<?php

namespace Statikbe\FilamentVoight\Resources\HostingResource\Schemas;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HostingTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.hosting.fields.name'))
                    ->searchable()
                    ->sortable(),
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
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                // Its environments keep existing, without a hosting.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
