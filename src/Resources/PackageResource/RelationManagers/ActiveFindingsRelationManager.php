<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Resources\Components\AuditFindingTableSchema;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;

class ActiveFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    public static function getIcon(Model $ownerRecord, string $pageClass): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::TabPackageActiveFindings->resolve();
    }

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
            ->emptyStateIcon(VoightIcon::EmptyStateAllClear->resolve())
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_active_findings_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_active_findings_description'));
    }
}
