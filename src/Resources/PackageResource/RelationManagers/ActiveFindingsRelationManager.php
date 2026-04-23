<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditRun;

class ActiveFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.active_findings_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'vulnerability',
                'auditRun.environment.project',
            ]))
            ->columns([
                TextColumn::make('vulnerability.severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->badge()
                    ->formatStateUsing(fn (Severity $state): string => $state->label())
                    ->color(fn (Severity $state): string => $state->color()),
                TextColumn::make('vulnerability.vulnerability_score')
                    ->label(voightTrans('models.package.view.columns.cvss'))
                    ->sortable(),
                TextColumn::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('vulnerability.summary')
                    ->label(voightTrans('models.package.view.columns.summary'))
                    ->limit(80)
                    ->tooltip(fn ($record): ?string => $record->vulnerability?->summary)
                    ->searchable(),
                TextColumn::make('installed_version')
                    ->label(voightTrans('models.package.view.columns.installed_version')),
                TextColumn::make('fixed_version')
                    ->label(voightTrans('models.package.view.columns.fixed_version'))
                    ->placeholder('—'),
                TextColumn::make('auditRun.environment.name')
                    ->label(voightTrans('models.package.view.columns.environment'))
                    ->sortable(),
                TextColumn::make('auditRun.environment.project.name')
                    ->label(voightTrans('models.package.view.columns.project'))
                    ->sortable(),
                TextColumn::make('auditRun.started_at')
                    ->label(voightTrans('models.package.view.columns.observed'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('latest_only')
                    ->label(voightTrans('models.package.view.filters.latest_only'))
                    ->trueLabel(voightTrans('models.package.view.filters.latest_only_true'))
                    ->falseLabel(voightTrans('models.package.view.filters.latest_only_false'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas(
                            'auditRun',
                            fn (Builder $ar) => $ar->whereRaw(
                                'started_at = (select max(a2.started_at) from voight_audit_runs a2 where a2.environment_id = voight_audit_runs.environment_id)'
                            ),
                        ),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q,
                    ),
                Filter::make('observed_at')
                    ->label(voightTrans('models.package.view.filters.observed_at'))
                    ->schema([
                        DatePicker::make('from')->label(voightTrans('models.package.view.filters.observed_from')),
                        DatePicker::make('until')->label(voightTrans('models.package.view.filters.observed_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $from) => $q->whereHas(
                                    'auditRun',
                                    fn (Builder $ar) => $ar->whereDate('started_at', '>=', $from),
                                ),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $until) => $q->whereHas(
                                    'auditRun',
                                    fn (Builder $ar) => $ar->whereDate('started_at', '<=', $until),
                                ),
                            );
                    }),
            ])
            ->groups([
                Group::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id')),
            ])
            ->defaultSort('vulnerability.vulnerability_score', 'desc');
    }
}
