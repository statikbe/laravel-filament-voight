<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas\AuditRunTableSchema;

class AuditRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditRuns';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.audit_run.plural');
    }

    public function table(Table $table): Table
    {
        // The project is implied by the page, so its column is hidden here.
        return AuditRunTableSchema::configure($table, showProject: false);
    }
}
