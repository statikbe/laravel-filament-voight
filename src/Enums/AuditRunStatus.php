<?php

namespace Statikbe\FilamentVoight\Enums;

enum AuditRunStatus: string
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
}
