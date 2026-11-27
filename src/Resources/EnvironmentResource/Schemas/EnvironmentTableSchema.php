<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas;

use Closure;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
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
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Exports\EnvironmentExporter;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\ApplyTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\Components\RemoveTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\TagConstraint;
use Statikbe\FilamentVoight\Resources\Components\TagsColumn;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Support\ProjectFaviconColumn;
use Statikbe\FilamentVoight\Support\VersionKey;

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
        $systemDetail = fn (string $column): Builder => EnvironmentSystemDetail::query()
            ->select($column)
            ->whereColumn('environment_id', (new $environmentModel)->getQualifiedKeyName());
        $sortBy = fn (string $column): Closure => fn (Builder $query, string $direction): Builder => $query->orderBy($systemDetail($column), $direction);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['project', 'systemDetails']))
            ->stackedOnMobile()
            ->columns([
                ProjectFaviconColumn::make(fn (Environment $record): Project => $record->project),
                TextColumn::make('project.name')
                    ->label(voightTrans('models.environment.fields.project'))
                    ->searchable()
                    ->visible($showProject)
                    ->toggleable(),
                EnvironmentNameColumn::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('url')
                    ->label(voightTrans('models.environment.fields.url'))
                    ->url(fn (Environment $record): ?string => $record->url, shouldOpenInNewTab: true)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('systemDetails.php_version')
                    ->label(voightTrans('models.environment.fields.php_version'))
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => VersionKey::orderBy($query, $systemDetail('php_version_sort'), $direction))
                    ->toggleable(),
                TextColumn::make('systemDetails.received_at')
                    ->label(voightTrans('models.environment.fields.system_details_received_at'))
                    ->since()
                    ->placeholder(voightTrans('models.environment.never_reported'))
                    ->sortable(query: $sortBy('received_at'))
                    ->toggleable(),
                TagsColumn::make(),
            ])
            ->persistColumnsInSession()
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        SelectConstraint::make('name')
                            ->icon(VoightIcon::FilterEnvironment->resolve())
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
                        TagConstraint::make([
                            'environment' => [Environment::class],
                            'project' => [Project::class, 'project'],
                            'customer' => [Customer::class, 'project.customer'],
                        ]),
                        ...($showProject ? [
                            ProjectConstraint::project('project_id'),
                            ProjectConstraint::customer('project.customer_id'),
                            ProjectConstraint::team('project.team_id'),
                        ] : []),
                        SelectConstraint::make('php_version')
                            ->icon(VoightIcon::FilterPhpVersion->resolve())
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
                            ->icon(VoightIcon::FilterSystemDetails->resolve())
                            ->label(voightTrans('models.environment.fields.system_details_received_at'))
                            ->emptyable(),
                        DateConstraint::make('systemDetails.received_at')
                            ->icon(VoightIcon::FilterDate->resolve())
                            ->label(voightTrans('models.environment.fields.system_details_received_at')),
                        BooleanConstraint::make('scan_nightly')
                            ->icon(VoightIcon::FilterNightlyScan->resolve())
                            ->label(voightTrans('models.environment.fields.scan_nightly')),
                        DateConstraint::make('scanned_at')
                            ->icon(VoightIcon::FilterDate->resolve())
                            ->label(voightTrans('models.environment.fields.scanned_at'))
                            ->nullable(),
                        SelectConstraint::make('latest_audit_status')
                            ->icon(VoightIcon::FilterAuditRunStatus->resolve())
                            ->label(voightTrans('models.environment.fields.latest_audit_status'))
                            ->attribute('latestFinishedAuditRun.status')
                            ->options(AuditRunStatus::class)
                            ->multiple(),
                        SelectConstraint::make('latest_sync_status')
                            ->icon(VoightIcon::FilterSyncStatus->resolve())
                            ->label(voightTrans('models.environment.fields.latest_sync_status'))
                            ->attribute('latestFinishedDependencySync.status')
                            ->options(DependencySyncStatus::class)
                            ->multiple(),
                        DateConstraint::make('created_at')
                            ->icon(VoightIcon::FilterDate->resolve())
                            ->label(voightTrans('models.environment.fields.created_at')),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->headerActions([
                ExportAction::make()
                    ->exporter(EnvironmentExporter::class),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->exporter(EnvironmentExporter::class),
                ApplyTagsBulkAction::make(),
                RemoveTagsBulkAction::make(),
            ])
            ->recordUrl(fn (Environment $record): string => EnvironmentResource::getUrl('view', [
                'project' => $record->project,
                'record' => $record,
            ]))
            ->defaultSort(fn (Builder $query): Builder => $sortBy('received_at')($query, 'desc')->orderBy('name'));
    }
}
