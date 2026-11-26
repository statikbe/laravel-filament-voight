<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum DependencySyncStatus: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return voightTrans('enums.dependency_sync_status.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Processing => 'info',
            self::Pending => 'warning',
            self::Failed => 'danger',
        };
    }

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::Pending => VoightIcon::DependencySyncStatusPending,
            self::Processing => VoightIcon::DependencySyncStatusProcessing,
            self::Completed => VoightIcon::DependencySyncStatusCompleted,
            self::Failed => VoightIcon::DependencySyncStatusFailed,
        };
    }

    public function icon(): string | BackedEnum | Htmlable
    {
        return $this->voightIcon()->resolve();
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function getIcon(): string | BackedEnum | Htmlable
    {
        return $this->icon();
    }
}
