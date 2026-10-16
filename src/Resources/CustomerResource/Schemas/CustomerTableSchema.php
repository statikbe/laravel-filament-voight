<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.customer.fields.name'))
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(voightTrans('models.customer.fields.slug'))
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                TernaryFilter::make('has_projects')
                    ->label(voightTrans('models.customer.list.has_projects'))
                    ->trueLabel(voightTrans('models.customer.list.has_projects_true'))
                    ->falseLabel(voightTrans('models.customer.list.has_projects_false'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('projects'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('projects'),
                    ),
            ])
            ->striped()
            ->defaultSort('name')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2)
            ->emptyStateHeading(voightTrans('models.customer.list.empty_heading'))
            ->emptyStateDescription(voightTrans('models.customer.list.empty_description'))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
