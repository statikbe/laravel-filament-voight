<?php

namespace Statikbe\FilamentVoight\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

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

    public function voightIcon(): VoightIcon
    {
        return match ($this) {
            self::Dependency => VoightIcon::DependencyKindDependency,
            self::Optional => VoightIcon::DependencyKindOptional,
            self::Peer => VoightIcon::DependencyKindPeer,
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
