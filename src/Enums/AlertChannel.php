<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

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

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::Email => VoightIcon::AlertChannelEmail,
            self::Slack => VoightIcon::AlertChannelSlack,
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
