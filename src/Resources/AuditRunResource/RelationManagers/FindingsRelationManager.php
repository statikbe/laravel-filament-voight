<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Resources\Components\FindingColumns;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;
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
            ->recordUrl(fn (AuditFinding $record): string => VulnerabilityResource::getUrl('view', ['record' => $record->vulnerability_id]))
            ->striped()
            ->columns([
                FindingColumns::severity(),
                FindingColumns::cvss(),
                FindingColumns::vulnerabilityId(),
                TextColumn::make('package.name')
                    ->label(voightTrans('models.package.label'))
                    ->searchable(),
                TextColumn::make('package.type')
                    ->label(voightTrans('widgets.active_findings.columns.package_type'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                FindingColumns::summary(),
                FindingColumns::installedVersion(),
                FindingColumns::fixedVersion(),
            ])
            ->recordActions([
                ViewFindingVulnerabilityAction::make()
                    ->iconButton()
                    ->tooltip(fn (Action $action): ?string => $action->getLabel()),
                WhyInstalledAction::make()
                    ->iconButton()
                    ->tooltip(fn (Action $action): ?string => $action->getLabel()),
            ])
            // Severity is an accessor derived from vulnerability_score, not a column,
            // so it cannot be sorted in SQL. Sorting by the score gives the same
            // worst-first order and matches the project's vulnerabilities table.
            ->defaultSort('vulnerability.vulnerability_score', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_active_findings_heading'))
            ->emptyStateDescription(voightTrans('models.audit_run.view.empty_findings'));
    }
}
