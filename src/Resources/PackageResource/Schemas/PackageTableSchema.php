<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Schemas;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Enums\PackageType;

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
                    ->counts('environmentPackages')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(voightTrans('models.package.fields.type'))
                    ->options(collect(PackageType::cases())->mapWithKeys(fn (PackageType $case) => [$case->value => $case->label()])),
            ]);
    }
}
