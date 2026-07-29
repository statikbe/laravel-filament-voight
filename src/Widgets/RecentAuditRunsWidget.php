<?php

namespace Statikbe\FilamentVoight\Widgets;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource;

class RecentAuditRunsWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return voightTrans('widgets.recent_audit_runs.heading');
    }

    public function table(Table $table): Table
    {
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        return $table
            ->query(
                $auditRunModel::query()
                    ->whereIn('id', $auditRunModel::latestIdsPerProject())
                    ->with('environment.project')
                    ->orderByDesc('started_at'),
            )
            ->columns([
                TextColumn::make('status')
                    ->label(voightTrans('models.audit_run.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (AuditRunStatus $state): string => $state->label())
                    ->color(fn (AuditRunStatus $state): string => $state->color()),
                TextColumn::make('environment.project.name')
                    ->label(voightTrans('models.project.label'))
                    ->searchable(),
                TextColumn::make('environment.name')
                    ->label(voightTrans('models.environment.label')),
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
            ->recordActions([
                // A widget is neither a resource page nor a relation manager, so
                // Filament cannot resolve the view URL on its own.
                ViewAction::make()
                    ->url(fn (AuditRun $record): string => AuditRunResource::getUrl(
                        'view',
                        ['record' => $record],
                    )),
            ])
            ->paginated([10])
            ->defaultPaginationPageOption(10);
    }
}
