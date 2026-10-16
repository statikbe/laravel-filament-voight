<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum AuditRunStatus: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return voightTrans('enums.audit_run_status.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Running => 'info',
            self::Pending => 'warning',
            self::Failed => 'danger',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Running => Heroicon::OutlinedArrowPath,
            self::Completed => Heroicon::OutlinedCheckCircle,
            self::Failed => Heroicon::OutlinedXCircle,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function getIcon(): Heroicon
    {
        return $this->icon();
    }
}
