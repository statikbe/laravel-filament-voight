<?php

namespace Statikbe\FilamentVoight\Notifications;

class AuditDigestNotification extends AuditAlertNotification
{
    protected function langGroup(): string
    {
        return 'audit_digest';
    }
}
