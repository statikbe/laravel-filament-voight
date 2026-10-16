<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->weight(FontWeight::Medium)
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
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('has_members')
                    ->label(voightTrans('models.team.list.has_members'))
                    ->trueLabel(voightTrans('models.team.list.has_members_true'))
                    ->falseLabel(voightTrans('models.team.list.has_members_false'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('users'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('users'),
                    ),
            ])
            ->striped()
            ->defaultSort('name')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading(voightTrans('models.team.list.empty_heading'))
            ->emptyStateDescription(voightTrans('models.team.list.empty_description'))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
