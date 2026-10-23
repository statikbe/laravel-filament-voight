<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\Concerns\HasCountedTabs;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ListProjects extends ListRecords
{
    use HasCountedTabs;

    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => $this->allTab(),
            'my_team' => $this->countedTab(
                'tabs.projects.my_team',
                fn (Builder $query): Builder => $query->whereHas('team.users', fn (Builder $users): Builder => $users->whereKey(auth()->id())),
            ),
            'critical_high_on_production' => $this->countedTab(
                'tabs.projects.critical_high_on_production',
                fn (Builder $query): Builder => $query->whereHas(
                    'environments',
                    fn (Builder $environments): Builder => tap($environments, fn (Builder $environments) => Environment::constrainToStages($environments, EnvironmentStage::Production))
                        ->whereHas('auditRuns.auditFindings', fn (Builder $findings): Builder => AuditFinding::constrainToCurrent($findings)
                            ->whereHas('vulnerability', fn (Builder $vulnerabilities) => Vulnerability::constrainToSeverities($vulnerabilities, Severity::Critical, Severity::High))),
                ),
            ),
        ];
    }
}
