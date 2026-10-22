<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;

class InstallationsRelationManager extends RelationManager
{
    protected static string $relationship = 'environmentPackages';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedServerStack;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.installations_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['environment.project', 'parents.package']))
            ->columns([
                TextColumn::make('environment.project.name')
                    ->label(voightTrans('models.package.view.columns.project'))
                    ->searchable()
                    ->sortable(),
                EnvironmentNameColumn::make('environment.name')
                    ->label(voightTrans('models.package.view.columns.environment'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->label(voightTrans('models.package.view.columns.installed_version')),
                IconColumn::make('is_direct')
                    ->label(voightTrans('models.package.view.columns.direct'))
                    ->boolean(),
                TextColumn::make('parents.package.name')
                    ->label(voightTrans('models.package.view.columns.required_by'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('—'),
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
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        ProjectConstraint::project('environment.project_id'),
                        EnvironmentStageConstraint::make('environment'),
                        TextConstraint::make('version')
                            ->label(voightTrans('models.package.view.columns.installed_version')),
                        BooleanConstraint::make('is_direct')
                            ->label(voightTrans('models.package.view.columns.direct')),
                        BooleanConstraint::make('is_dev')
                            ->label(voightTrans('models.package.view.columns.dev')),
                        DateConstraint::make('scanned_at')
                            ->label(voightTrans('models.package.view.columns.last_scan'))
                            ->attribute('environment.scanned_at')
                            ->nullable(),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                WhyInstalledAction::make(),
            ])
            ->defaultSort('environment.scanned_at', 'desc');
    }
}
