<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameEntry;
use Statikbe\FilamentVoight\Resources\Components\InfolistStat;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class AuditRunInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InfolistStat::strip([
                InfolistStat::make(
                    TextEntry::make('status')
                        ->label(voightTrans('models.audit_run.fields.status'))
                        ->badge(),
                ),
                InfolistStat::make(
                    TextEntry::make('trigger')
                        ->label(voightTrans('models.audit_run.fields.trigger'))
                        ->badge()
                        ->placeholder('—'),
                ),
                InfolistStat::make(
                    TextEntry::make('stat_max_severity')
                        ->label(voightTrans('models.audit_run.fields.max_severity'))
                        ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: voightTrans('hints.max_severity'))
                        ->icon(Heroicon::OutlinedShieldExclamation)
                        ->state(fn (AuditRun $record): ?Severity => self::maxSeverity($record))
                        ->formatStateUsing(fn (Severity $state): string => $state->label())
                        ->badge()
                        ->color(fn (?Severity $state): string => $state?->color() ?? 'gray')
                        ->placeholder(voightTrans('models.audit_run.view.no_findings')),
                ),
                InfolistStat::make(
                    TextEntry::make('stat_findings_count')
                        ->label(voightTrans('models.audit_run.fields.findings_count'))
                        ->icon(Heroicon::OutlinedListBullet)
                        ->state(fn (AuditRun $record): int => $record->auditFindings()->count()),
                ),
            ]),
            Section::make(voightTrans('models.audit_run.view.error'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->iconColor(AuditRunStatus::Failed->color())
                ->columnSpanFull()
                ->visible(fn (AuditRun $record): bool => filled($record->error_message))
                ->components([
                    TextEntry::make('error_message')
                        ->hiddenLabel(),
                ]),
            Section::make(voightTrans('models.audit_run.sections.details'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 5])
                ->components([
                    TextEntry::make('environment.project.name')
                        ->label(voightTrans('models.audit_run.fields.project'))
                        ->url(fn (AuditRun $record): string => self::projectUrl($record))
                        ->color('primary')
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition(IconPosition::After)
                        ->extraAttributes(['class' => 'underline']),
                    EnvironmentNameEntry::make('environment.name')
                        ->label(voightTrans('models.audit_run.fields.environment'))
                        ->url(fn (AuditRun $record): string => EnvironmentResource::getUrl('view', [
                            'project' => $record->environment->project,
                            'record' => $record->environment,
                        ])),
                    TextEntry::make('started_at')
                        ->label(voightTrans('models.audit_run.fields.started_at'))
                        ->since()
                        ->dateTimeTooltip(),
                    TextEntry::make('completed_at')
                        ->label(voightTrans('models.audit_run.fields.completed_at'))
                        ->since()
                        ->dateTimeTooltip()
                        ->placeholder('—'),
                    TextEntry::make('formatted_duration')
                        ->label(voightTrans('models.audit_run.fields.duration'))
                        ->placeholder('—'),
                ]),
        ]);
    }

    private static function maxSeverity(AuditRun $record): ?Severity
    {
        $score = $record->vulnerabilities()->max('voight_vulnerabilities.vulnerability_score');

        return $score === null ? null : Severity::fromScore((float) $score);
    }

    private static function projectUrl(AuditRun $record): string
    {
        return ProjectResource::getUrl('view', ['record' => $record->environment->project]);
    }
}
