<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Resources\Components\AuditFindingTableSchema;
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
        return AuditFindingTableSchema::configure($table, showProject: false, singleRun: true)
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
