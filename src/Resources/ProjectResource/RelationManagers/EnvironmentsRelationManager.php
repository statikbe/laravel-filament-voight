<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EnvironmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'environments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.environment.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->sortable(),
                TextColumn::make('scanned_at')
                    ->label(voightTrans('models.environment.fields.scanned_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(voightTrans('models.environment.never_scanned')),
                TextColumn::make('environment_packages_count')
                    ->label(voightTrans('models.package.plural'))
                    ->counts('environmentPackages')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
