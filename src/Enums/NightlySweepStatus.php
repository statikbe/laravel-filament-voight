<?php

namespace Statikbe\FilamentVoight\Enums;

enum NightlySweepStatus: string
{
    case Scanning = 'scanning';
    case Recording = 'recording';
    case Completed = 'completed';
    case Failed = 'failed';
}
