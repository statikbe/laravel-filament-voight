<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Exports\CustomerExporter;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Resources\Components\ApplyTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\RemoveTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\TagConstraint;
use Statikbe\FilamentVoight\Resources\Components\TagsColumn;

class CustomerTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.customer.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(voightTrans('models.customer.fields.slug'))
                    ->searchable(),
                TextColumn::make('projects_count')
                    ->label(voightTrans('models.project.plural'))
                    ->counts('projects')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TagsColumn::make(),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([TagConstraint::make(['customer' => [Customer::class]])]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(CustomerExporter::class),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->exporter(CustomerExporter::class),
                ApplyTagsBulkAction::make(),
                RemoveTagsBulkAction::make(),
                DeleteBulkAction::make(),
            ]);
    }
}
