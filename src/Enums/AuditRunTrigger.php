<?php

namespace Statikbe\FilamentVoight\Enums;

enum AuditRunTrigger: string
{
    use Concerns\HasOptions;

    case PostSync = 'post_sync';
    case Nightly = 'nightly';
    case Manual = 'manual';

    public function label(): string
    {
        return voightTrans('enums.audit_run_trigger.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::PostSync => 'info',
            self::Nightly => 'gray',
            self::Manual => 'warning',
        };
    }
}
