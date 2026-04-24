<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;

class KnownVulnerabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'vulnerablePackageRanges';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.known_vulnerabilities_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vulnerability'))
            ->columns([
                TextColumn::make('vulnerability.severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->badge()
                    ->formatStateUsing(fn (Severity $state): string => $state->label())
                    ->color(fn (Severity $state): string => $state->color()),
                TextColumn::make('vulnerability.vulnerability_score')
                    ->label(voightTrans('models.package.view.columns.cvss'))
                    ->sortable(),
                TextColumn::make('vulnerability.source')
                    ->label(voightTrans('models.package.view.columns.source'))
                    ->formatStateUsing(fn (VulnerabilitySource $state): string => $state->label()),
                TextColumn::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('vulnerability.summary')
                    ->label(voightTrans('models.package.view.columns.summary'))
                    ->limit(80)
                    ->tooltip(fn ($record): ?string => $record->vulnerability?->summary)
                    ->searchable(),
                TextColumn::make('affected_range')
                    ->label(voightTrans('models.package.view.columns.affected_range')),
                TextColumn::make('fixed_version')
                    ->label(voightTrans('models.package.view.columns.fixed_version'))
                    ->placeholder('—'),
                TextColumn::make('vulnerability.published_at')
                    ->label(voightTrans('models.package.view.columns.published'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('vulnerability.modified_at')
                    ->label(voightTrans('models.package.view.columns.modified'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->options(Severity::options())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }
                        [$min, $max] = Severity::from($data['value'])->scoreRange();

                        return $query->whereHas(
                            'vulnerability',
                            fn (Builder $q) => $q
                                ->where('vulnerability_score', '>=', $min)
                                ->where('vulnerability_score', '<=', $max),
                        );
                    }),
            ])
            ->defaultSort('vulnerability.vulnerability_score', 'desc');
    }
}
