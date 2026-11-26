<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum TagRuleStatus: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;

    case Active = 'active';
    case Paused = 'paused';
    case Failed = 'failed';

    public function label(): string
    {
        return voightTrans('enums.tag_rule_status.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'gray',
            self::Failed => 'danger',
        };
    }

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::Active => VoightIcon::TagRuleStatusActive,
            self::Paused => VoightIcon::TagRuleStatusPaused,
            self::Failed => VoightIcon::TagRuleStatusFailed,
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
