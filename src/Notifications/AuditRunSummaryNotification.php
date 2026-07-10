<?php

namespace Statikbe\FilamentVoight\Notifications;

class AuditRunSummaryNotification extends AuditAlertNotification
{
    protected function langGroup(): string
    {
        return 'audit_run_summary';
    }
}
