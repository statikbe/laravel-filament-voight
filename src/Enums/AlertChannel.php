<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AlertChannel: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;
    case Email = 'email';
    case Slack = 'slack';

    public function label(): string
    {
        return voightTrans('enums.alert_channel.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Email => 'info',
            self::Slack => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Email => 'heroicon-o-envelope',
            self::Slack => 'heroicon-o-chat-bubble-left-right',
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
