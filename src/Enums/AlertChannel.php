<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

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

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Email => Heroicon::OutlinedEnvelope,
            self::Slack => Heroicon::OutlinedChatBubbleLeftRight,
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
