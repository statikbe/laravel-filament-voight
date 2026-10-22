<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;
use Statikbe\FilamentVoight\Resources\Components\SeverityConstraint;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

class KnownVulnerabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'vulnerablePackageRanges';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedBookOpen;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.known_vulnerabilities_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vulnerability'))
            ->recordUrl(fn ($record): ?string => $record->vulnerability_id
                ? VulnerabilityResource::getUrl('view', ['record' => $record->vulnerability_id])
                : null)
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
                QueryBuilder::make()
                    ->constraints([
                        SeverityConstraint::make('severity', 'vulnerability')
                            ->label(voightTrans('models.package.view.columns.severity')),
                        SelectConstraint::make('source')
                            ->label(voightTrans('models.package.view.columns.source'))
                            ->attribute('vulnerability.source')
                            ->options(VulnerabilitySource::options())
                            ->multiple(),
                        TextConstraint::make('source_id')
                            ->label(voightTrans('models.package.view.columns.source_id'))
                            ->attribute('vulnerability.source_id'),
                        TextConstraint::make('summary')
                            ->label(voightTrans('models.package.view.columns.summary'))
                            ->attribute('vulnerability.summary'),
                        TextConstraint::make('affected_range')
                            ->label(voightTrans('models.package.view.columns.affected_range')),
                        TextConstraint::make('fixed_version')
                            ->label(voightTrans('models.package.view.columns.fixed_version'))
                            ->nullable(),
                        DateConstraint::make('published_at')
                            ->label(voightTrans('models.package.view.columns.published'))
                            ->attribute('vulnerability.published_at'),
                        DateConstraint::make('modified_at')
                            ->label(voightTrans('models.package.view.columns.modified'))
                            ->attribute('vulnerability.modified_at'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->defaultSort('vulnerability.vulnerability_score', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_known_vulnerabilities_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_known_vulnerabilities_description'));
    }
}
