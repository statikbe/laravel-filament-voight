<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EnvironmentIssueType: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;

    case SyncFailed = 'sync_failed';
    case ScanFailed = 'scan_failed';
    case SyncWarning = 'sync_warning';
    case NeverSynced = 'never_synced';

    public function label(): string
    {
        return voightTrans('enums.environment_issue_type.' . $this->value);
    }

    /**
     * Sort key: the lowest value is the worst issue.
     */
    public function priority(): int
    {
        return match ($this) {
            self::SyncFailed => 0,
            self::ScanFailed => 1,
            self::SyncWarning => 2,
            self::NeverSynced => 3,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SyncFailed, self::ScanFailed => 'danger',
            self::SyncWarning => 'warning',
            self::NeverSynced => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SyncFailed, self::ScanFailed => 'heroicon-o-x-circle',
            self::SyncWarning => 'heroicon-o-exclamation-triangle',
            self::NeverSynced => 'heroicon-o-information-circle',
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

    public function getIcon(): string
    {
        return $this->icon();
    }
}
