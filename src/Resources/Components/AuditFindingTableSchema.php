<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class AuditFindingTableSchema
{
    /**
     * @param  bool  $showProject  Hidden where the project is already implied by the page.
     * @param  bool  $showPackage  Hidden where the package is already implied by the page.
     * @param  bool  $showVulnerability  Hidden where the vulnerability is already implied by the page.
     * @param  bool  $singleRun  The findings of one audit run: hides the environment and observed-at columns and the "latest scan only" toggle, as the run implies them.
     * @param  bool  $latestCompletedOnly  The "latest scan only" filter follows the latest completed run instead of the latest run of any status.
     */
    public static function configure(
        Table $table,
        bool $showProject = true,
        bool $showPackage = true,
        bool $showVulnerability = true,
        bool $singleRun = false,
        bool $latestCompletedOnly = false,
    ): Table {
        $constraints = [];

        if ($showPackage) {
            $constraints[] = TextConstraint::make('package_name')
                ->label(voightTrans('models.package.label'))
                ->attribute('package.name');
            $constraints[] = SelectConstraint::make('package_type')
                ->label(voightTrans('widgets.active_findings.columns.package_type'))
                ->attribute('package.type')
                ->options(PackageType::options())
                ->multiple();
        }

        if ($showVulnerability) {
            $constraints[] = SeverityConstraint::make('severity', 'vulnerability')
                ->label(voightTrans('models.vulnerability.fields.severity'));
            $constraints[] = SelectConstraint::make('vulnerability_source')
                ->label(voightTrans('models.vulnerability.fields.source'))
                ->attribute('vulnerability.source')
                ->options(VulnerabilitySource::options())
                ->multiple();
            $constraints[] = TextConstraint::make('vulnerability_source_id')
                ->label(voightTrans('models.vulnerability.fields.source_id'))
                ->attribute('vulnerability.source_id');
        }

        $constraints[] = TextConstraint::make('installed_version')
            ->label(voightTrans('models.package.view.columns.installed_version'));
        // "Is filled" / "is blank" doubles as "has a fix available".
        $constraints[] = TextConstraint::make('fixed_version')
            ->label(voightTrans('models.package.view.columns.fixed_version'))
            ->nullable();

        if (! $singleRun) {
            $constraints[] = DateConstraint::make('observed_at')
                ->label(voightTrans('models.package.view.columns.observed'))
                ->attribute('auditRun.started_at');
            $constraints[] = EnvironmentStageConstraint::make('auditRun.environment');
        }

        if ($showProject) {
            $constraints[] = ProjectConstraint::project('auditRun.environment.project_id');
        }

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'vulnerability',
                'package',
                'auditRun.environment.project',
            ]))
            ->columns([
                TextColumn::make('vulnerability.severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->badge()
                    ->formatStateUsing(fn (Severity $state): string => $state->label())
                    ->color(fn (Severity $state): string => $state->color())
                    ->visible($showVulnerability),
                TextColumn::make('vulnerability.vulnerability_score')
                    ->label(voightTrans('models.package.view.columns.cvss'))
                    ->sortable()
                    ->toggleable()
                    ->visible($showVulnerability),
                TextColumn::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id'))
                    ->searchable()
                    ->copyable()
                    ->toggleable()
                    ->visible($showVulnerability),
                TextColumn::make('vulnerability.summary')
                    ->label(voightTrans('models.package.view.columns.summary'))
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn (AuditFinding $record): ?string => $record->vulnerability?->summary)
                    ->searchable()
                    ->visible($showVulnerability),
                TextColumn::make('package.name')
                    ->label(voightTrans('models.package.label'))
                    ->url(fn (AuditFinding $record): string => PackageResource::getUrl('view', ['record' => $record->package_id]))
                    ->searchable()
                    ->sortable()
                    ->visible($showPackage),
                TextColumn::make('package.type')
                    ->label(voightTrans('widgets.active_findings.columns.package_type'))
                    ->badge()
                    ->formatStateUsing(fn (PackageType $state): string => $state->label())
                    ->visible($showPackage),
                TextColumn::make('installed_version')
                    ->label(voightTrans('models.package.view.columns.installed_version'))
                    ->toggleable(),
                TextColumn::make('fixed_version')
                    ->label(voightTrans('models.package.view.columns.fixed_version'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('auditRun.environment.project.project_code')
                    ->label(voightTrans('models.package.view.columns.project'))
                    ->tooltip(fn (AuditFinding $record): ?string => $record->auditRun->environment->project->name)
                    ->url(fn (AuditFinding $record): string => ProjectResource::getUrl('view', ['record' => $record->auditRun->environment->project]))
                    ->searchable(['project_code', 'name'])
                    ->sortable()
                    ->toggleable()
                    ->visible($showProject),
                EnvironmentNameColumn::make('auditRun.environment.name')
                    ->label(voightTrans('models.package.view.columns.environment'))
                    ->sortable()
                    ->toggleable()
                    ->visible(! $singleRun),
                TextColumn::make('auditRun.started_at')
                    ->label(voightTrans('models.package.view.columns.observed'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $singleRun),
            ])
            ->filters([
                ...($singleRun ? [] : [
                    TernaryFilter::make('latest_only')
                        ->label(voightTrans('models.package.view.filters.latest_only'))
                        ->trueLabel(voightTrans('models.package.view.filters.latest_only_true'))
                        ->falseLabel(voightTrans('models.package.view.filters.latest_only_false'))
                        ->default(true)
                        ->queries(
                            true: fn (Builder $q) => $latestCompletedOnly ? AuditFinding::constrainToLatestCompleted($q) : AuditFinding::constrainToCurrent($q),
                            false: fn (Builder $q) => $q,
                            blank: fn (Builder $q) => $q,
                        ),
                ]),
                QueryBuilder::make()->constraints($constraints),
            ], layout: FiltersLayout::AboveContentCollapsible);
    }
}
