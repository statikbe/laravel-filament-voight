<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Why a parent requires a child. A peer parent does not bring its own copy of the
 * child, so bumping it never changes which version of the child is installed.
 */
enum DependencyKind: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;

    case Dependency = 'dependency';
    case Optional = 'optional';
    case Peer = 'peer';

    public function label(): string
    {
        return voightTrans('enums.dependency_kind.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Dependency => 'gray',
            self::Optional => 'info',
            self::Peer => 'warning',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Dependency => Heroicon::OutlinedArrowDown,
            self::Optional => Heroicon::OutlinedQuestionMarkCircle,
            self::Peer => Heroicon::OutlinedLink,
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
