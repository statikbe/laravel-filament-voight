<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas\AuditRunTableSchema;

class AuditRunResource extends Resource
{
    protected static ?string $model = AuditRun::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return voightTrans('models.audit_run.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.audit_run.plural');
    }

    public static function table(Table $table): Table
    {
        return AuditRunTableSchema::configure($table);
    }

    /**
     * Audit runs are machine-generated; editing them by hand would corrupt the audit trail.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            'finding' => FindingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditRuns::route('/'),
            'view' => ViewAuditRun::route('/{record}'),
        ];
    }
}
