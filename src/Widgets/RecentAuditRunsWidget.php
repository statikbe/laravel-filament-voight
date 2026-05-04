<?php

namespace Statikbe\FilamentVoight\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Models\AuditRun;

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
        return $table
            ->query(
                AuditRun::query()
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
                TextColumn::make('duration')
                    ->label(voightTrans('widgets.recent_audit_runs.columns.duration'))
                    ->state(fn (AuditRun $record): string => $this->formatDuration($record))
                    ->placeholder('—'),
            ])
            ->paginated([10])
            ->defaultPaginationPageOption(10);
    }

    protected function formatDuration(AuditRun $record): string
    {
        if ($record->started_at === null || $record->completed_at === null) {
            return '—';
        }

        $totalSeconds = max(0, $record->completed_at->diffInSeconds($record->started_at));
        $minutes = intdiv((int) $totalSeconds, 60);
        $seconds = (int) $totalSeconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$seconds}s";
        }

        return "{$seconds}s";
    }
}
