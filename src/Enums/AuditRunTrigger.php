<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

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

    public function icon(): Heroicon
    {
        return match ($this) {
            self::PostSync => Heroicon::OutlinedArrowPath,
            self::Nightly => Heroicon::OutlinedMoon,
            self::Manual => Heroicon::OutlinedHandRaised,
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
