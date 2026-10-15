<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas;

use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Support\ProjectFaviconColumn;

class EnvironmentTableSchema
{
    /**
     * @param  bool  $showProject  Hidden where the project (and so its customer and team) is already implied by the page.
     */
    public static function configure(Table $table, bool $showProject = true): Table
    {
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        // Follows whatever scoping the host applies to the project list.
        $projectIds = fn (): Builder => ProjectResource::getEloquentQuery()->select('id');

        // Sorts through a correlated subquery so no join is needed next to the eager-loaded relation.
        $sortBy = fn (string $column): Closure => fn (Builder $query, string $direction): Builder => $query->orderBy(
            EnvironmentSystemDetail::query()->select($column)->whereColumn('environment_id', (new $environmentModel)->getQualifiedKeyName()),
            $direction,
        );

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['project', 'systemDetails']))
            ->columns([
                ProjectFaviconColumn::make(fn (Environment $record): Project => $record->project),
                TextColumn::make('project.name')
                    ->label(voightTrans('models.environment.fields.project'))
                    ->searchable()
                    ->visible($showProject),
                EnvironmentNameColumn::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label(voightTrans('models.environment.fields.url'))
                    ->url(fn (Environment $record): ?string => $record->url, shouldOpenInNewTab: true)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('systemDetails.php_version')
                    ->label(voightTrans('models.environment.fields.php_version'))
                    ->placeholder('—')
                    ->sortable(query: $sortBy('php_version')),
                TextColumn::make('systemDetails.received_at')
                    ->label(voightTrans('models.environment.fields.system_details_received_at'))
                    ->since()
                    ->placeholder(voightTrans('models.environment.never_reported'))
                    ->sortable(query: $sortBy('received_at')),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        SelectConstraint::make('name')
                            ->label(voightTrans('models.environment.fields.name'))
                            ->options(fn (): array => $environmentModel::query()
                                ->whereIn('project_id', $projectIds())
                                ->distinct()
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all())
                            ->searchable()
                            ->multiple(),
                        EnvironmentStageConstraint::make(),
                        ...($showProject ? [
                            ProjectConstraint::project('project_id'),
                            ProjectConstraint::customer('project.customer_id'),
                            ProjectConstraint::team('project.team_id'),
                        ] : []),
                        SelectConstraint::make('php_version')
                            ->label(voightTrans('models.environment.fields.php_version'))
                            ->attribute('systemDetails.php_version')
                            ->options(fn (): array => EnvironmentSystemDetail::query()
                                ->whereIn('environment_id', $environmentModel::query()->whereIn('project_id', $projectIds())->select('id'))
                                ->whereNotNull('php_version')
                                ->distinct()
                                ->orderBy('php_version')
                                ->pluck('php_version', 'php_version')
                                ->all())
                            ->searchable()
                            ->multiple(),
                        RelationshipConstraint::make('systemDetails')
                            ->label(voightTrans('models.environment.fields.system_details_received_at'))
                            ->emptyable(),
                        DateConstraint::make('systemDetails.received_at')
                            ->label(voightTrans('models.environment.fields.system_details_received_at')),
                        BooleanConstraint::make('scan_nightly')
                            ->label(voightTrans('models.environment.fields.scan_nightly')),
                        DateConstraint::make('scanned_at')
                            ->label(voightTrans('models.environment.fields.scanned_at'))
                            ->nullable(),
                        SelectConstraint::make('latest_audit_status')
                            ->label(voightTrans('models.environment.fields.latest_audit_status'))
                            ->attribute('latestFinishedAuditRun.status')
                            ->options(AuditRunStatus::class)
                            ->multiple(),
                        SelectConstraint::make('latest_sync_status')
                            ->label(voightTrans('models.environment.fields.latest_sync_status'))
                            ->attribute('latestFinishedDependencySync.status')
                            ->options(DependencySyncStatus::class)
                            ->multiple(),
                        DateConstraint::make('created_at')
                            ->label(voightTrans('models.environment.fields.created_at')),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordUrl(fn (Environment $record): string => EnvironmentResource::getUrl('view', [
                'project' => $record->project,
                'record' => $record,
            ]))
            ->defaultSort(fn (Builder $query): Builder => $sortBy('received_at')($query, 'desc')->orderBy('name'));
    }
}
