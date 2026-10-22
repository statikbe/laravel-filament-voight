<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Filters\PackageVersionConstraint;

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
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted')),
                QueryBuilder::make()
                    ->constraints([
                        PackageVersionConstraint::make(),
                        TextConstraint::make('project_code')
                            ->label(voightTrans('models.project.fields.project_code')),
                        TextConstraint::make('name')
                            ->label(voightTrans('models.project.fields.name')),
                        RelationshipConstraint::make('customer')
                            ->label(voightTrans('models.project.fields.customer'))
                            ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->preload())
                            ->emptyable(),
                        RelationshipConstraint::make('team')
                            ->label(voightTrans('models.project.fields.team'))
                            ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->preload())
                            ->emptyable(),
                        BooleanConstraint::make('is_muted')
                            ->label(voightTrans('models.project.fields.is_muted')),
                        RelationshipConstraint::make('environments')
                            ->label(voightTrans('models.environment.plural'))
                            ->multiple()
                            ->emptyable(),
                        TextConstraint::make('environment_name')
                            ->label(voightTrans('filters.project.environment_name'))
                            ->relationship('environments', 'name'),
                        DateConstraint::make('environment_scanned_at')
                            ->label(voightTrans('filters.project.environment_scanned_at'))
                            ->relationship('environments', 'scanned_at'),
                        TextConstraint::make('package_name')
                            ->label(voightTrans('filters.project.package_name'))
                            ->relationship('environments.environmentPackages.package', 'name'),
                        SelectConstraint::make('package_type')
                            ->label(voightTrans('filters.project.package_type'))
                            ->relationship('environments.environmentPackages.package', 'type')
                            ->options(collect(PackageType::cases())->mapWithKeys(fn (PackageType $type): array => [$type->value => $type->label()])->all()),
                        DateConstraint::make('created_at')
                            ->label(voightTrans('fields.created_at')),
                    ])
                    ->constraintPickerColumns(2),
            ])
            ->filtersFormWidth(Width::FourExtraLarge)
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
