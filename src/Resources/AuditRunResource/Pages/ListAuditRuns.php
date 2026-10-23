<?php

namespace Statikbe\FilamentVoight\Resources\AuditRunResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\Concerns\HasCountedTabs;

class ListAuditRuns extends ListRecords
{
    use HasCountedTabs;

    protected static string $resource = AuditRunResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'latest_per_environment' => $this->countedTab(
                'tabs.audit_runs.latest_per_environment',
                fn (Builder $query): Builder => $query->whereIn($query->qualifyColumn('id'), AuditRun::latestIdsPerEnvironment()),
            ),
            'failed' => $this->countedTab(
                'tabs.audit_runs.failed',
                fn (Builder $query): Builder => $query->where($query->qualifyColumn('status'), AuditRunStatus::Failed),
            ),
            'all' => $this->allTab(),
        ];
    }
}
