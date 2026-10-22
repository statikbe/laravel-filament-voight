<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Actions\ViewFindingVulnerabilityAction;

class FindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditFindings';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedShieldExclamation;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.vulnerability.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['vulnerability', 'package']))
            ->columns([
                TextColumn::make('vulnerability.severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->badge(),
                TextColumn::make('vulnerability.vulnerability_score')
                    ->label(voightTrans('models.package.view.columns.cvss'))
                    ->sortable(),
                TextColumn::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id'))
                    ->searchable(),
                TextColumn::make('package.name')
                    ->label(voightTrans('models.package.label'))
                    ->searchable(),
                TextColumn::make('package.type')
                    ->label(voightTrans('widgets.active_findings.columns.package_type'))
                    ->badge(),
                TextColumn::make('vulnerability.summary')
                    ->label(voightTrans('models.package.view.columns.summary'))
                    ->limit(60),
                TextColumn::make('installed_version')
                    ->label(voightTrans('models.package.view.columns.installed_version')),
                TextColumn::make('fixed_version')
                    ->label(voightTrans('models.package.view.columns.fixed_version'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewFindingVulnerabilityAction::make(),
                WhyInstalledAction::make(),
            ])
            // Severity is an accessor derived from vulnerability_score, not a column,
            // so it cannot be sorted in SQL. Sorting by the score gives the same
            // worst-first order and matches the project's vulnerabilities table.
            ->defaultSort('vulnerability.vulnerability_score', 'desc');
    }
}
