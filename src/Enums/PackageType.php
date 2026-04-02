<?php

namespace Statikbe\FilamentVoight\Enums;

enum PackageType: string
{
    use Concerns\HasOptions;
    case Composer = 'composer';
    case Npm = 'npm';

    public function label(): string
    {
        return voightTrans('enums.package_type.' . $this->value);
    }
}
