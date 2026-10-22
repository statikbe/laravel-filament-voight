<?php

namespace Statikbe\FilamentVoight\Enums;

enum OsvScanStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
