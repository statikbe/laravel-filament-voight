<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Schemas;

use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\OpenPackageWebsiteAction;

class PackageTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount(['findings as open_findings_count' => fn (Builder $findings): Builder => $findings->open()]))
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.package.fields.name'))
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(voightTrans('models.package.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (PackageType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('latest_version')
                    ->label(voightTrans('models.package.fields.latest_version'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextColumn::make('latest_version_updated_at')
                    ->label(voightTrans('models.package.fields.latest_version_updated_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('environment_packages_count')
                    ->label(voightTrans('models.package.fields.installations'))
                    ->counts(['environmentPackages' => fn (Builder $query) => $query->select(DB::raw('count(distinct environment_id)'))])
                    ->sortable(),
                TextColumn::make('open_findings_count')
                    ->label(voightTrans('models.package.list.open_findings'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(voightTrans('models.package.fields.type'))
                    ->options(PackageType::options()),
            ])
            ->striped()
            ->defaultSort('name')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->emptyStateIcon(Heroicon::OutlinedCubeTransparent)
            ->emptyStateHeading(voightTrans('models.package.list.empty_heading'))
            ->emptyStateDescription(voightTrans('models.package.list.empty_description'))
            ->recordActions([
                ViewAction::make(),
                OpenPackageWebsiteAction::make(),
            ]);
    }
}
