<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum AlertFrequency: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return voightTrans('enums.alert_frequency.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Immediate => 'danger',
            self::Daily => 'warning',
            self::Weekly => 'info',
        };
    }

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::Immediate => VoightIcon::AlertFrequencyImmediate,
            self::Daily => VoightIcon::AlertFrequencyDaily,
            self::Weekly => VoightIcon::AlertFrequencyWeekly,
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
