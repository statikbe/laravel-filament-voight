<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
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

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['environment.project.name', 'environment.project.project_code', 'environment.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with('environment.project')
            ->whereIn('id', (FilamentVoight::config()->getAuditRunModel())::latestIdsPerEnvironment());
    }

    // No single title column exists, so the title is composed from the project and environment.
    public static function getGlobalSearchResultTitle(Model $record): string | Htmlable
    {
        return $record->environment->project->name . ' — ' . $record->environment->name;
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            voightTrans('models.audit_run.fields.status') => $record->status->label(),
            voightTrans('models.audit_run.fields.started_at') => $record->started_at?->toDayDateTimeString(),
        ], fn (mixed $value): bool => $value !== null);
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
