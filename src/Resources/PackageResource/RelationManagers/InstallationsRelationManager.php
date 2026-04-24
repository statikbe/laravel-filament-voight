<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InstallationsRelationManager extends RelationManager
{
    protected static string $relationship = 'environmentPackages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.installations_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('environment.project'))
            ->columns([
                TextColumn::make('environment.project.name')
                    ->label(voightTrans('models.package.view.columns.project'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('environment.name')
                    ->label(voightTrans('models.package.view.columns.environment'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->label(voightTrans('models.package.view.columns.installed_version')),
                IconColumn::make('is_direct')
                    ->label(voightTrans('models.package.view.columns.direct'))
                    ->boolean(),
                IconColumn::make('is_dev')
                    ->label(voightTrans('models.package.view.columns.dev'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('environment.scanned_at')
                    ->label(voightTrans('models.package.view.columns.last_scan'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(voightTrans('models.environment.never_scanned')),
            ])
            ->defaultSort('environment.scanned_at', 'desc');
    }
}
