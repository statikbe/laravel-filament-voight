<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PackageType: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;
    case Composer = 'composer';
    case Npm = 'npm';

    public function label(): string
    {
        return voightTrans('enums.package_type.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Composer => 'warning',
            self::Npm => 'danger',
        };
    }

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::Composer => VoightIcon::PackageTypeComposer,
            self::Npm => VoightIcon::PackageTypeNpm,
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
