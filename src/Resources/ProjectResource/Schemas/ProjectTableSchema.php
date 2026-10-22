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
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentStageConstraint;
use Statikbe\FilamentVoight\Resources\Components\ProjectConstraint;
use Statikbe\FilamentVoight\Resources\Components\SeverityConstraint;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ProjectTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label(voightTrans('models.project.fields.team'))
                    ->formatStateUsing(fn (Project $record): View => view('filament-voight::team.avatar-name', ['team' => $record->team]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('environments_count')
                    ->label(voightTrans('models.environment.plural'))
                    ->counts('environments')
                    ->sortable(),
                IconColumn::make('is_muted')
                    ->label(voightTrans('models.project.fields.is_muted'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('project_code')
                            ->label(voightTrans('models.project.fields.project_code')),
                        TextConstraint::make('name')
                            ->label(voightTrans('models.project.fields.name')),
                        ProjectConstraint::customer('customer_id'),
                        ProjectConstraint::team('team_id'),
                        BooleanConstraint::make('is_muted')
                            ->label(voightTrans('models.project.fields.is_muted')),
                        DateConstraint::make('created_at')
                            ->label(voightTrans('fields.created_at')),
                        RelationshipConstraint::make('environments')
                            ->label(voightTrans('models.environment.plural'))
                            ->multiple(),
                        EnvironmentStageConstraint::make('environments'),
                        // "Current" means a finding in an environment's latest audit run; matches projects with any such finding of the severity.
                        SeverityConstraint::make('current_severity', fn (Builder $query, Severity $severity) => $query
                            ->whereHas('environments.auditRuns.auditFindings', fn (Builder $finding) => AuditFinding::constrainToCurrent($finding)
                                ->whereHas('vulnerability', fn (Builder $vulnerability) => $vulnerability->whereBetween('vulnerability_score', $severity->scoreRange()))))
                            ->label(voightTrans('models.project.fields.current_severity')),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => ProjectResource::getUrl('view', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
