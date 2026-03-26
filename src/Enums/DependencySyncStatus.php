<?php

namespace Statikbe\FilamentVoight\Enums;

enum DependencySyncStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return voightTrans('enums.dependency_sync_status.' . $this->value);
    }
}
