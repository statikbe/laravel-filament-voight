<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\ExportColumn;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditRun;

class AuditRunExporter extends VoightExporter
{
    protected static ?string $model = AuditRun::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('environment.project.name')
                ->label(voightTrans('models.audit_run.fields.project')),
            ExportColumn::make('environment.name')
                ->label(voightTrans('models.audit_run.fields.environment')),
            ExportColumn::make('trigger')
                ->label(voightTrans('models.audit_run.fields.trigger'))
                ->formatStateUsing(fn (AuditRunTrigger $state): string => $state->label()),
            ExportColumn::make('status')
                ->label(voightTrans('models.audit_run.fields.status'))
                ->formatStateUsing(fn (AuditRunStatus $state): string => $state->label()),
            ExportColumn::make('max_severity')
                ->label(voightTrans('models.audit_run.fields.max_severity'))
                ->state(fn (AuditRun $record): ?Severity => $record->max_vulnerability_score === null
                    ? null
                    : Severity::fromScore((float) $record->max_vulnerability_score))
                ->formatStateUsing(fn (?Severity $state): ?string => $state?->label()),
            ExportColumn::make('audit_findings_count')
                ->label(voightTrans('models.audit_run.fields.findings_count')),
            ExportColumn::make('started_at')
                ->label(voightTrans('models.audit_run.fields.started_at')),
            ExportColumn::make('completed_at')
                ->label(voightTrans('models.audit_run.fields.completed_at')),
            ExportColumn::make('formatted_duration')
                ->label(voightTrans('models.audit_run.fields.duration')),
        ];
    }
}
