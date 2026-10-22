<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Resources\Components\AuditFindingTableSchema;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;

class ActiveFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedShieldExclamation;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.active_findings_title');
    }

    public function table(Table $table): Table
    {
        return AuditFindingTableSchema::configure($table, showPackage: false)
            ->groups([
                Group::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id')),
            ])
            ->recordActions([
                WhyInstalledAction::make(),
            ])
            ->defaultSort('vulnerability.vulnerability_score', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_active_findings_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_active_findings_description'));
    }
}
