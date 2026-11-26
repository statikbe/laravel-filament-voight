<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum AuditRunTrigger: string implements HasColor, HasIcon, HasLabel
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

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::PostSync => VoightIcon::AuditRunTriggerPostSync,
            self::Nightly => VoightIcon::AuditRunTriggerNightly,
            self::Manual => VoightIcon::AuditRunTriggerManual,
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
