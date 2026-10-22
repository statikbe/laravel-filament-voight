<?php

namespace Statikbe\FilamentVoight\Parsers;

use RuntimeException;
use Statikbe\FilamentVoight\Enums\SyncWarning;

/**
 * Thrown by a parser for a lockfile format it recognises but cannot read. The
 * sync job records the warning and skips the file instead of failing the sync.
 */
class UnsupportedLockfileException extends RuntimeException
{
    public function __construct(public readonly SyncWarning $warning)
    {
        parent::__construct("Unsupported lockfile: {$warning->value}");
    }
}
