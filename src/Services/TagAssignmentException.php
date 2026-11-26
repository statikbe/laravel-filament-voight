<?php

namespace Statikbe\FilamentVoight\Services;

use RuntimeException;

/**
 * Thrown before any write when tags cannot be assigned; carries every problem found.
 */
class TagAssignmentException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
