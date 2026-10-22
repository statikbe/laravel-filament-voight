<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Schemas;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\OpenPackageWebsiteAction;

class PackageTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.package.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(voightTrans('models.package.fields.type'))
                    ->formatStateUsing(fn (PackageType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('latest_version')
                    ->label(voightTrans('models.package.fields.latest_version'))
                    ->placeholder('-'),
                TextColumn::make('latest_version_updated_at')
                    ->label(voightTrans('models.package.fields.latest_version_updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('environment_packages_count')
                    ->label(voightTrans('models.package.fields.installations'))
                    ->counts(['environmentPackages' => fn (Builder $query) => $query->select(DB::raw('count(distinct environment_id)'))])
                    ->sortable(),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('name')
                            ->label(voightTrans('models.package.fields.name')),
                        SelectConstraint::make('type')
                            ->label(voightTrans('models.package.fields.type'))
                            ->options(PackageType::options())
                            ->multiple(),
                        TextConstraint::make('latest_version')
                            ->label(voightTrans('models.package.fields.latest_version')),
                        DateConstraint::make('latest_version_updated_at')
                            ->label(voightTrans('models.package.fields.latest_version_updated_at')),
                        EnvironmentStageConstraint::make('environmentPackages.environment'),
                        RelationshipConstraint::make('environmentPackages')
                            ->label(voightTrans('models.package.fields.installations'))
                            ->multiple(),
                        RelationshipConstraint::make('findings')
                            ->label(voightTrans('models.package.fields.findings'))
                            ->multiple(),
                        RelationshipConstraint::make('vulnerablePackageRanges')
                            ->label(voightTrans('models.package.fields.known_vulnerabilities'))
                            ->multiple(),
                        ProjectConstraint::project('environmentPackages.environment.project_id'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make(),
                OpenPackageWebsiteAction::make(),
            ]);
    }
}
