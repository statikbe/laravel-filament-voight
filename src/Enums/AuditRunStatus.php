<?php

namespace Statikbe\FilamentVoight\Enums;

enum AuditRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return voightTrans('enums.audit_run_status.' . $this->value);
    }
}
