<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Statikbe\FilamentVoight\Enums\Framework;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\UpgradeStatus;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\ApplyTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectPackageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectStackConstraint;
use Statikbe\FilamentVoight\Resources\Components\RemoveTagsBulkAction;
use Statikbe\FilamentVoight\Resources\Components\SeverityConstraint;
use Statikbe\FilamentVoight\Resources\Components\TagConstraint;
use Statikbe\FilamentVoight\Resources\Components\TagsColumn;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Services\ProjectStackService;
use Statikbe\FilamentVoight\Support\ProjectFaviconColumn;

class ProjectTableSchema
{
    /**
     * @param  bool  $showTeam  Hidden where the team is already implied by the page.
     */
    public static function configure(Table $table, bool $showTeam = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ProjectStackService::withStack($query))
            ->columns([
                ProjectFaviconColumn::make(fn (Project $record): Project => $record),
                TextColumn::make('project_code')
                    ->label(voightTrans('models.project.fields.project_code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(voightTrans('models.project.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(voightTrans('models.project.fields.customer'))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label(voightTrans('models.project.fields.team'))
                    ->formatStateUsing(fn (Project $record): View => view('filament-voight::team.avatar-name', ['team' => $record->team]))
                    ->searchable()
                    ->sortable()
                    ->visible($showTeam),
                TextColumn::make('environments_count')
                    ->label(voightTrans('models.environment.plural'))
                    ->counts('environments')
                    ->sortable(),
                ...collect(Framework::cases())->map(fn (Framework $framework): TextColumn => TextColumn::make(strtolower($framework->name) . '_version')
                    ->label($framework->label())
                    ->state(fn (Project $record): ?string => ProjectStackService::version($record, $framework))
                    ->placeholder('-')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => ProjectStackService::orderByVersion($query, $framework, $direction))
                    ->toggleable(isToggledHiddenByDefault: true))->all(),
                TextColumn::make('php_version')
                    ->label(voightTrans('models.project.fields.php_version'))
                    ->state(fn (Project $record): ?string => ProjectStackService::phpVersion($record))
                    ->placeholder('-')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => ProjectStackService::orderByPhpVersion($query, $direction))
                    ->toggleable(),
                TextColumn::make('upgrade_status')
                    ->label(voightTrans('models.project.fields.upgrade_status'))
                    ->state(fn (Project $record): UpgradeStatus => ProjectStackService::upgradeStatus($record))
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => ProjectStackService::orderByUpgradeStatus($query, $direction))
                    ->description(function (Project $record): ?HtmlString {
                        $lines = ProjectStackService::upgradeDescription($record);

                        return $lines === null ? null : new HtmlString(nl2br(e($lines)));
                    }, position: 'below')
                    ->toggleable(),
                IconColumn::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TagsColumn::make(),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('project_code')
                            ->icon(VoightIcon::FilterProjectCode->resolve())
                            ->label(voightTrans('models.project.fields.project_code')),
                        TextConstraint::make('name')
                            ->icon(VoightIcon::FilterProject->resolve())
                            ->label(voightTrans('models.project.fields.name')),
                        ProjectConstraint::customer('customer_id'),
                        ...($showTeam ? [ProjectConstraint::team('team_id')] : []),
                        BooleanConstraint::make('is_muted')
                            ->icon(VoightIcon::FilterMuted->resolve())
                            ->label(voightTrans('models.project.fields.is_muted')),
                        DateConstraint::make('created_at')
                            ->icon(VoightIcon::FilterDate->resolve())
                            ->label(voightTrans('fields.created_at')),
                        RelationshipConstraint::make('environments')
                            ->icon(VoightIcon::FilterEnvironment->resolve())
                            ->label(voightTrans('models.environment.plural'))
                            ->multiple(),
                        EnvironmentStageConstraint::make('environments'),
                        ProjectStackConstraint::phpVersion(),
                        ProjectStackConstraint::upgradeStatus(),
                        ProjectPackageConstraint::make(),
                        TagConstraint::make([
                            'project' => [Project::class],
                            'customer' => [Customer::class, 'customer'],
                        ]),
                        // "Current" means a finding in an environment's latest audit run; matches projects with any such finding of the severity.
                        SeverityConstraint::make('current_severity', fn (Builder $query, Severity $severity) => $query
                            ->whereHas('environments.auditRuns.auditFindings', fn (Builder $finding) => AuditFinding::constrainToCurrent($finding)
                                ->whereHas('vulnerability', fn (Builder $vulnerability) => $vulnerability->whereBetween('vulnerability_score', $severity->scoreRange()))))
                            ->label(voightTrans('models.project.fields.current_severity')),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->defaultSort('upgrade_status', 'asc')
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => ProjectResource::getUrl('view', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                ApplyTagsBulkAction::make(),
                RemoveTagsBulkAction::make(),
                DeleteBulkAction::make(),
            ]);
    }
}
