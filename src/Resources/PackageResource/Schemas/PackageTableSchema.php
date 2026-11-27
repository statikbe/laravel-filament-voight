<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Schemas;

use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
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
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Exports\PackageExporter;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\ApplyTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\Components\RemoveTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\TagConstraint;
use Statikbe\FilamentVoight\Resources\Components\TagsColumn;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\OpenPackageWebsiteAction;

class PackageTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
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
                TagsColumn::make(),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('name')
                            ->icon(VoightIcon::FilterPackage->resolve())
                            ->label(voightTrans('models.package.fields.name')),
                        SelectConstraint::make('type')
                            ->icon(VoightIcon::FilterPackageType->resolve())
                            ->label(voightTrans('models.package.fields.type'))
                            ->options(PackageType::options())
                            ->multiple(),
                        TextConstraint::make('latest_version')
                            ->icon(VoightIcon::FilterVersion->resolve())
                            ->label(voightTrans('models.package.fields.latest_version')),
                        DateConstraint::make('latest_version_updated_at')
                            ->icon(VoightIcon::FilterDate->resolve())
                            ->label(voightTrans('models.package.fields.latest_version_updated_at')),
                        EnvironmentStageConstraint::make('environmentPackages.environment'),
                        TagConstraint::make([
                            'package' => [Package::class],
                            'environment' => [Environment::class, 'environmentPackages.environment'],
                            'project' => [Project::class, 'environmentPackages.environment.project'],
                            'customer' => [Customer::class, 'environmentPackages.environment.project.customer'],
                        ]),
                        RelationshipConstraint::make('environmentPackages')
                            ->icon(VoightIcon::FilterInstallations->resolve())
                            ->label(voightTrans('models.package.fields.installations'))
                            ->multiple(),
                        RelationshipConstraint::make('findings')
                            ->icon(VoightIcon::FilterFindings->resolve())
                            ->label(voightTrans('models.package.fields.findings'))
                            ->multiple(),
                        RelationshipConstraint::make('vulnerablePackageRanges')
                            ->icon(VoightIcon::FilterVulnerabilities->resolve())
                            ->label(voightTrans('models.package.fields.known_vulnerabilities'))
                            ->multiple(),
                        ProjectConstraint::project('environmentPackages.environment.project_id'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make(),
                OpenPackageWebsiteAction::make(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(PackageExporter::class),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->exporter(PackageExporter::class),
                ApplyTagsBulkAction::make(),
                RemoveTagsBulkAction::make(),
            ]);
    }
}
