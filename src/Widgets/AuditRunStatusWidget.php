<?php

namespace Statikbe\FilamentVoight\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Models\AuditRun;

class AuditRunStatusWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int | array | null $columns = 4;

    protected function getHeading(): ?string
    {
        return voightTrans('widgets.audit_run_status.heading');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return collect([
            AuditRunStatus::Completed,
            AuditRunStatus::Running,
            AuditRunStatus::Pending,
            AuditRunStatus::Failed,
        ])
            ->map(fn (AuditRunStatus $status): Stat => Stat::make(
                $status->label(),
                AuditRun::query()->where('status', $status->value)->count(),
            )
                ->color($status->color())
                ->icon($this->getIconForStatus($status)))
            ->all();
    }

    protected function getIconForStatus(AuditRunStatus $status): Heroicon
    {
        return match ($status) {
            AuditRunStatus::Completed => Heroicon::OutlinedCheckCircle,
            AuditRunStatus::Running => Heroicon::OutlinedArrowPath,
            AuditRunStatus::Pending => Heroicon::OutlinedClock,
            AuditRunStatus::Failed => Heroicon::OutlinedXCircle,
        };
    }
}
