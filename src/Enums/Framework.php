<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentVoight\Enums\Concerns\HasOptions;

/**
 * The Composer packages whose installed version defines a project's stack.
 */
enum Framework: string implements HasLabel
{
    use HasOptions;

    case Laravel = 'laravel/framework';
    case Filament = 'filament/filament';
    case Nova = 'laravel/nova';
    case Craft = 'craftcms/cms';

    /**
     * Hand-maintained: bump when a new major ships.
     */
    public function latestMajor(): int
    {
        return match ($this) {
            self::Laravel => 13,
            self::Filament => 5,
            self::Nova => 5,
            self::Craft => 5,
        };
    }

    public function label(): string
    {
        return voightTrans('enums.framework.' . strtolower($this->name));
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
