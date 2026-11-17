<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentVoight\Enums\Concerns\HasOptions;

/**
 * Whether a project runs behind the latest major of a tracked framework (see Framework::latestMajor()).
 */
enum UpgradeStatus: string implements HasColor, HasLabel
{
    use HasOptions;

    case NeedsUpgrade = 'needs_upgrade';
    case Current = 'current';
    case Unknown = 'unknown';

    public function label(): string
    {
        return voightTrans('enums.upgrade_status.' . $this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NeedsUpgrade => 'warning',
            self::Current => 'success',
            self::Unknown => 'gray',
        };
    }
}
