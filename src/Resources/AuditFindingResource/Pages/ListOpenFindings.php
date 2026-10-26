<?php

namespace Statikbe\FilamentVoight\Resources\AuditFindingResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditFindingResource;
use Statikbe\FilamentVoight\Resources\Concerns\HasCountedTabs;

class ListOpenFindings extends ListRecords
{
    use HasCountedTabs;

    protected static string $resource = AuditFindingResource::class;

    /**
     * Mirrors the "latest scan only" filter, so the badges count what the list shows.
     *
     * @return Builder<AuditFinding>
     */
    protected function getTabBaseQuery(): Builder
    {
        $query = static::getResource()::getEloquentQuery();
        $latestOnlyState = $this->tableFilters['latest_only'] ?? null;
        $isLatestOnly = $latestOnlyState === null || ($latestOnlyState['value'] ?? false);

        return $isLatestOnly ? AuditFinding::constrainToLatestCompleted($query) : $query;
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $production = fn (Builder $query) => Environment::constrainToStages($query, EnvironmentStage::Production);

        return [
            'production_critical_high' => $this->countedTab(
                'tabs.open_findings.production_critical_high',
                fn (Builder $query): Builder => $query
                    ->whereHas('auditRun.environment', $production)
                    ->whereHas('vulnerability', fn (Builder $query) => Vulnerability::constrainToSeverities($query, Severity::Critical, Severity::High)),
            ),
            'production' => $this->countedTab(
                'tabs.open_findings.production',
                fn (Builder $query): Builder => $query->whereHas('auditRun.environment', $production),
            ),
            'other_stages' => $this->countedTab(
                'tabs.open_findings.other_stages',
                fn (Builder $query): Builder => $query->whereDoesntHave('auditRun.environment', $production),
            ),
            'all' => $this->allTab(),
        ];
    }
}
