<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\Components\TeamOption;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ProjectTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project_code')
                    ->label(voightTrans('models.project.fields.project_code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(voightTrans('models.project.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(voightTrans('models.project.fields.customer'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label(voightTrans('models.project.fields.team'))
                    ->formatStateUsing(fn (Project $record): View => view('filament-voight::team.avatar-name', ['team' => $record->team]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('environments_count')
                    ->label(voightTrans('models.environment.plural'))
                    ->counts('environments')
                    ->sortable(),
                IconColumn::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label(voightTrans('models.project.fields.customer'))
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('team_id')
                    ->label(voightTrans('models.project.fields.team'))
                    ->relationship('team', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Team $record): string => TeamOption::label($record))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->allowHtml())
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => ProjectResource::getUrl('view', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
