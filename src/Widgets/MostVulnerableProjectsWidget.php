<?php

namespace Statikbe\FilamentVoight\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Project;

class MostVulnerableProjectsWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return voightTrans('widgets.most_vulnerable_projects.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Project::query()
                    ->withCount([
                        'findings as total_findings_count' => fn (Builder $q): Builder => $q->whereIn(
                            'voight_audit_findings.audit_run_id',
                            AuditRun::latestIdsPerEnvironment(),
                        ),
                        'findings as critical_findings_count' => fn (Builder $q): Builder => $q
                            ->whereIn('voight_audit_findings.audit_run_id', AuditRun::latestIdsPerEnvironment())
                            ->whereHas('vulnerability', fn (Builder $vq): Builder => $vq->where('vulnerability_score', '>=', 9.0)),
                        'findings as high_findings_count' => fn (Builder $q): Builder => $q
                            ->whereIn('voight_audit_findings.audit_run_id', AuditRun::latestIdsPerEnvironment())
                            ->whereHas('vulnerability', fn (Builder $vq): Builder => $vq->whereBetween('vulnerability_score', [7.0, 8.9])),
                    ])
                    ->having('total_findings_count', '>', 0)
                    ->orderByDesc('total_findings_count'),
            )
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.project.label'))
                    ->searchable(),
                TextColumn::make('critical_findings_count')
                    ->label(voightTrans('widgets.most_vulnerable_projects.columns.critical'))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('high_findings_count')
                    ->label(voightTrans('widgets.most_vulnerable_projects.columns.high'))
                    ->badge()
                    ->color('warning'),
                TextColumn::make('total_findings_count')
                    ->label(voightTrans('widgets.most_vulnerable_projects.columns.total'))
                    ->sortable(),
            ])
            ->paginated([8])
            ->defaultPaginationPageOption(8);
    }
}
