<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas\AuditRunTableSchema;

class AuditRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditRuns';

    /**
     * Lets the row action link through to the audit run page. Without it Filament
     * cannot build a URL and falls back to a modal, which a relation manager has
     * no infolist to fill — so it renders empty.
     */
    protected static ?string $relatedResource = AuditRunResource::class;

    public static function getIcon(Model $ownerRecord, string $pageClass): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::TabProjectAuditRuns->resolve();
    }

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
