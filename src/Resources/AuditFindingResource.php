<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditFindingResource\Pages\ListOpenFindings;
use Statikbe\FilamentVoight\Resources\Components\AuditFindingTableSchema;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Actions\ViewFindingVulnerabilityAction;

class AuditFindingResource extends Resource
{
    protected static ?string $model = AuditFinding::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $slug = 'open-findings';

    protected static ?int $navigationSort = 1;

    // Gated like the vulnerabilities it lists, so no extra Shield permission is needed.
    public static function canViewAny(): bool
    {
        return VulnerabilityResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.audit_finding.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.audit_finding.plural');
    }

    /**
     * Counts distinct vulnerabilities, so one critical vulnerability on three production environments is one call to action.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = AuditFinding::constrainToLatestCompleted(AuditFinding::query())
            ->whereHas('auditRun.environment', fn (Builder $query) => Environment::constrainToStages($query, EnvironmentStage::Production))
            ->whereHas('vulnerability', fn (Builder $query) => Vulnerability::constrainToSeverities($query, Severity::Critical))
            ->distinct()
            ->count('vulnerability_id');

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string | Htmlable | null
    {
        return voightTrans('navigation.badges.open_findings');
    }

    public static function table(Table $table): Table
    {
        $vulnerabilityModel = FilamentVoight::config()->getVulnerabilityModel();
        $score = fn (): Builder => $vulnerabilityModel::query()->select('vulnerability_score')->whereColumn('voight_vulnerabilities.id', 'voight_audit_findings.vulnerability_id');
        $projectCode = fn (): Builder => FilamentVoight::config()->getProjectModel()::query()
            ->select('voight_projects.project_code')
            ->join('voight_environments', 'voight_environments.project_id', '=', 'voight_projects.id')
            ->join('voight_audit_runs', 'voight_audit_runs.environment_id', '=', 'voight_environments.id')
            ->whereColumn('voight_audit_runs.id', 'voight_audit_findings.audit_run_id');

        return AuditFindingTableSchema::configure($table, latestCompletedOnly: true)
            ->recordActions([ViewFindingVulnerabilityAction::make()])
            ->groups([
                Group::make('vulnerability_id')
                    ->label(voightTrans('models.vulnerability.label'))
                    ->getTitleFromRecordUsing(fn (AuditFinding $record): string => "{$record->vulnerability->source_id} — " . Str::limit($record->vulnerability->summary, 80))
                    ->getDescriptionFromRecordUsing(fn (AuditFinding $record): string => $record->vulnerability->severity->label() . ' · ' . voightTrans('models.package.view.columns.cvss') . ' ' . number_format((float) $record->vulnerability->vulnerability_score, 1))
                    // Score then id keeps equal-scored vulnerabilities from interleaving their rows.
                    ->orderQueryUsing(fn (Builder $query): Builder => $query
                        ->orderByDesc($score())
                        ->orderBy('voight_audit_findings.vulnerability_id')),
                // The project sits three relations away, so key, order and scope go through it explicitly.
                Group::make('project')
                    ->label(voightTrans('models.project.label'))
                    ->getKeyFromRecordUsing(fn (AuditFinding $record): string => (string) $record->auditRun->environment->project_id)
                    ->getTitleFromRecordUsing(fn (AuditFinding $record): string => "{$record->auditRun->environment->project->project_code} — {$record->auditRun->environment->project->name}")
                    ->orderQueryUsing(fn (Builder $query): Builder => $query
                        ->orderBy($projectCode())
                        ->orderByDesc($score()))
                    ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => $query
                        ->whereHas('auditRun.environment', fn (Builder $environments): Builder => $environments->where('project_id', $key))),
            ])
            ->groupingDirectionSettingHidden()
            ->defaultGroup('vulnerability_id')
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy($projectCode()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpenFindings::route('/'),
        ];
    }
}
