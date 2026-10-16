<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\Components\TeamOption;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ProjectTableSchema
{
    /**
     * @param  bool  $showCustomer  Hidden where the customer is already implied by the page.
     */
    public static function configure(Table $table, bool $showCustomer = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['customer', 'team'])
                ->select($query->qualifyColumn('*'))
                ->addSelect([
                    'open_findings_count' => self::openFindingsSubquery('count(*)'),
                    'open_max_score' => self::openFindingsSubquery('max(voight_vulnerabilities.vulnerability_score)'),
                ]))
            ->columns([
                TextColumn::make('project_code')
                    ->label(voightTrans('models.project.fields.project_code'))
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(voightTrans('models.project.fields.name'))
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(voightTrans('models.project.fields.customer'))
                    ->searchable()
                    ->sortable()
                    ->visible($showCustomer),
                TextColumn::make('team.name')
                    ->label(voightTrans('models.project.fields.team'))
                    ->formatStateUsing(fn (Project $record): View => view('filament-voight::team.avatar-name', ['team' => $record->team]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('environments_count')
                    ->label(voightTrans('models.environment.plural'))
                    ->counts('environments')
                    ->sortable(),
                TextColumn::make('open_severity')
                    ->label(voightTrans('models.project.list.worst_severity'))
                    ->state(fn (Project $record): ?Severity => (int) $record->open_findings_count === 0
                        ? null
                        : Severity::fromScore((float) $record->open_max_score))
                    ->badge()
                    ->formatStateUsing(fn (Severity $state): string => $state->label())
                    ->color(fn (Severity $state): string => $state->color())
                    ->icon(fn (Severity $state): Heroicon => $state->icon())
                    ->placeholder('—')
                    ->sortable(['open_max_score']),
                TextColumn::make('open_findings_count')
                    ->label(voightTrans('models.project.fields.open_findings'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label(voightTrans('models.project.fields.customer'))
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->visible($showCustomer),
                SelectFilter::make('team_id')
                    ->label(voightTrans('models.project.fields.team'))
                    ->relationship('team', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Team $record): string => TeamOption::label($record))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->allowHtml())
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted')),
            ])
            ->striped()
            ->defaultSort('name')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->emptyStateIcon(Heroicon::OutlinedFolderOpen)
            ->emptyStateHeading(voightTrans('models.project.list.empty_heading'))
            ->emptyStateDescription(voightTrans('models.project.list.empty_description'))
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    /**
     * Aggregate over a project's open findings, as a correlated subquery so the list stays one query.
     *
     * @return Builder<AuditFinding>
     */
    private static function openFindingsSubquery(string $aggregate): Builder
    {
        return AuditFinding::query()
            ->open()
            ->join('voight_vulnerabilities', 'voight_vulnerabilities.id', '=', 'voight_audit_findings.vulnerability_id')
            ->join('voight_audit_runs', 'voight_audit_runs.id', '=', 'voight_audit_findings.audit_run_id')
            ->join('voight_environments', 'voight_environments.id', '=', 'voight_audit_runs.environment_id')
            ->whereColumn('voight_environments.project_id', 'voight_projects.id')
            ->selectRaw($aggregate);
    }
}
