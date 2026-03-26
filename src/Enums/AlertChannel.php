<?php

namespace Statikbe\FilamentVoight\Enums;

enum AlertChannel: string
{
    case Email = 'email';
    case Slack = 'slack';

    public function label(): string
    {
        return voightTrans('enums.alert_channel.' . $this->value);
    }
}
