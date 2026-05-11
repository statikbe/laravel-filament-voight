<?php

namespace Statikbe\FilamentVoight\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;

class ActiveFindingsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return voightTrans('widgets.active_findings.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AuditFinding::query()
                    ->whereIn('audit_run_id', AuditRun::latestIdsPerEnvironment())
                    ->with(['vulnerability', 'package', 'auditRun.environment.project'])
                    ->join('voight_vulnerabilities', 'voight_audit_findings.vulnerability_id', '=', 'voight_vulnerabilities.id')
                    ->orderByDesc('voight_vulnerabilities.vulnerability_score')
                    ->select('voight_audit_findings.*'),
            )
            ->columns([
                TextColumn::make('vulnerability.severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->badge()
                    ->formatStateUsing(fn (Severity $state): string => $state->label())
                    ->color(fn (Severity $state): string => $state->color()),
                TextColumn::make('vulnerability.vulnerability_score')
                    ->label(voightTrans('models.package.view.columns.cvss')),
                TextColumn::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('vulnerability.summary')
                    ->label(voightTrans('models.package.view.columns.summary'))
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn (AuditFinding $record): ?string => $record->vulnerability?->summary)
                    ->searchable(),
                TextColumn::make('package.name')
                    ->label(voightTrans('widgets.active_findings.columns.package'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'package',
                        fn (Builder $q) => $q->where('name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('package.type')
                    ->label(voightTrans('widgets.active_findings.columns.package_type'))
                    ->badge()
                    ->formatStateUsing(fn (PackageType $state): string => $state->label()),
                TextColumn::make('auditRun.environment.project.name')
                    ->label(voightTrans('models.package.view.columns.project')),
                TextColumn::make('auditRun.environment.name')
                    ->label(voightTrans('models.package.view.columns.environment')),
                TextColumn::make('auditRun.started_at')
                    ->label(voightTrans('widgets.active_findings.columns.observed'))
                    ->dateTime(),
            ])
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_active_findings_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_active_findings_description'));
    }
}
