<?php

namespace Statikbe\FilamentVoight\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Vulnerability;

class SeverityOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int | array | null $columns = 4;

    protected function getHeading(): ?string
    {
        return voightTrans('widgets.severity_overview.heading');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return collect([Severity::Critical, Severity::High, Severity::Medium, Severity::Low])
            ->map(function (Severity $severity): Stat {
                [$min, $max] = $severity->scoreRange();
                $sparkline = $this->getDailyFindingsSparkline($severity);
                $weeklyTotal = array_sum($sparkline);

                return Stat::make(
                    $severity->label(),
                    Vulnerability::query()
                        ->whereBetween('vulnerability_score', [$min, $max])
                        ->count(),
                )
                    ->description(voightTrans('widgets.severity_overview.findings_this_week', ['count' => $weeklyTotal]))
                    ->color($severity->color())
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->chart($sparkline);
            })
            ->all();
    }

    /**
     * Returns the number of audit findings per day for the last 7 days at the given severity.
     *
     * @return array<int>
     */
    protected function getDailyFindingsSparkline(Severity $severity): array
    {
        [$min, $max] = $severity->scoreRange();

        $start = Carbon::now()->subDays(6)->startOfDay();

        $counts = AuditFinding::query()
            ->join('voight_vulnerabilities', 'voight_audit_findings.vulnerability_id', '=', 'voight_vulnerabilities.id')
            ->join('voight_audit_runs', 'voight_audit_findings.audit_run_id', '=', 'voight_audit_runs.id')
            ->whereBetween('voight_vulnerabilities.vulnerability_score', [$min, $max])
            ->where('voight_audit_runs.started_at', '>=', $start)
            ->selectRaw('DATE(voight_audit_runs.started_at) as day, COUNT(DISTINCT voight_audit_findings.id) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return collect(range(0, 6))
            ->map(function (int $offset) use ($start, $counts): int {
                $day = $start->copy()->addDays($offset)->toDateString();

                return (int) ($counts[$day] ?? 0);
            })
            ->all();
    }
}
