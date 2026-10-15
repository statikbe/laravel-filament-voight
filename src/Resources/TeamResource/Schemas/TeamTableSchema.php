<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Team;

class TeamTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->state(fn (Team $record): string => $record->getAvatarUrl())
                    ->imageHeight(32)
                    ->circular(),
                TextColumn::make('name')
                    ->label(voightTrans('models.team.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label(voightTrans('models.team.fields.users'))
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label(voightTrans('models.project.plural'))
                    ->counts('projects')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
