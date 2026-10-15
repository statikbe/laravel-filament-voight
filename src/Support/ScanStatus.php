<?php

namespace Statikbe\FilamentVoight\Support;

final class ScanStatus
{
    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public ?ScanResponse $result = null,
        public ?ScanError $error = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status === self::DONE || $this->status === self::FAILED;
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }
}
