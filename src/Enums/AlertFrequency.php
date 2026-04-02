<?php

namespace Statikbe\FilamentVoight\Enums;

enum AlertFrequency: string
{
    use Concerns\HasOptions;
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return voightTrans('enums.alert_frequency.' . $this->value);
    }
}
