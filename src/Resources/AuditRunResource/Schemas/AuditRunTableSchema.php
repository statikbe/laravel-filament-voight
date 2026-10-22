<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Schemas;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\Components\SeverityConstraint;

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
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label(voightTrans('models.audit_run.fields.started_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label(voightTrans('models.audit_run.fields.completed_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('formatted_duration')
                    ->label(voightTrans('models.audit_run.fields.duration'))
                    ->placeholder('—'),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        ...($showProject ? [ProjectConstraint::project('environment.project_id')] : []),
                        EnvironmentStageConstraint::make('environment'),
                        SelectConstraint::make('status')
                            ->label(voightTrans('models.audit_run.fields.status'))
                            ->options(AuditRunStatus::class)
                            ->multiple(),
                        SelectConstraint::make('trigger')
                            ->label(voightTrans('models.audit_run.fields.trigger'))
                            ->options(AuditRunTrigger::class)
                            ->multiple(),
                        // The max severity is derived from the highest vulnerability score, which a HasManyThrough aggregate constraint cannot filter.
                        SeverityConstraint::make('max_severity', function (Builder $query, Severity $severity): void {
                            [$minimum, $maximum] = $severity->scoreRange();

                            $query
                                ->whereHas('vulnerabilities', fn (Builder $vulnerability) => $vulnerability->whereBetween('vulnerability_score', [$minimum, $maximum]))
                                ->whereDoesntHave('vulnerabilities', fn (Builder $vulnerability) => $vulnerability->where('vulnerability_score', '>', $maximum));
                        })->label(voightTrans('models.audit_run.fields.max_severity')),
                        DateConstraint::make('started_at')
                            ->label(voightTrans('models.audit_run.fields.started_at')),
                        DateConstraint::make('completed_at')
                            ->label(voightTrans('models.audit_run.fields.completed_at'))
                            ->nullable(),
                        RelationshipConstraint::make('auditFindings')
                            ->label(voightTrans('models.audit_run.fields.findings_count'))
                            ->multiple(),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->defaultSort('started_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
