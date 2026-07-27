<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

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

    public function icon(): string
    {
        return match ($this) {
            self::Immediate => 'heroicon-o-bolt',
            self::Daily => 'heroicon-o-calendar-days',
            self::Weekly => 'heroicon-o-calendar',
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
