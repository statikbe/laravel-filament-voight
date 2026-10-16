<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas;

use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;

class AuditRunTableSchema
{
    /**
     * @param  bool  $showProject  Hidden where the project is already implied by the page.
     */
    public static function configure(Table $table, bool $showProject = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('environment.project')
                ->withCount('auditFindings')
                ->withMax('vulnerabilities as max_vulnerability_score', 'vulnerability_score'))
            ->columns([
                TextColumn::make('environment.project.name')
                    ->label(voightTrans('models.audit_run.fields.project'))
                    ->searchable()
                    ->visible($showProject),
                EnvironmentNameColumn::make('environment.name')
                    ->label(voightTrans('models.audit_run.fields.environment'))
                    ->searchable(),
                TextColumn::make('trigger')
                    ->label(voightTrans('models.audit_run.fields.trigger'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(voightTrans('models.audit_run.fields.status'))
                    ->badge(),
                TextColumn::make('max_severity')
                    ->label(voightTrans('models.audit_run.fields.max_severity'))
                    ->state(fn (AuditRun $record): ?Severity => $record->max_vulnerability_score === null
                        ? null
                        : Severity::fromScore((float) $record->max_vulnerability_score))
                    ->badge()
                    ->placeholder('—')
                    ->sortable(['max_vulnerability_score']),
                TextColumn::make('audit_findings_count')
                    ->label(voightTrans('models.audit_run.fields.findings_count'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label(voightTrans('models.audit_run.fields.started_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->weight(FontWeight::Medium)
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label(voightTrans('models.audit_run.fields.completed_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('formatted_duration')
                    ->label(voightTrans('models.audit_run.fields.duration'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(voightTrans('models.audit_run.fields.status'))
                    ->options(AuditRunStatus::class),
                SelectFilter::make('trigger')
                    ->label(voightTrans('models.audit_run.fields.trigger'))
                    ->options(AuditRunTrigger::class),
                SelectFilter::make('environment')
                    ->label(voightTrans('models.audit_run.fields.environment'))
                    ->relationship('environment', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->striped()
            ->defaultSort('started_at', 'desc')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(voightTrans('models.audit_run.empty.heading'))
            ->emptyStateDescription(voightTrans('models.audit_run.empty.description'))
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
