<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

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

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedCube;
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
