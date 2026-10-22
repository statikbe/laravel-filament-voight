<?php

namespace Statikbe\FilamentVoight\Support;

use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;

final class EnvironmentIssue
{
    /**
     * @param  string  $message  already translated
     * @param  Carbon|null  $occurredAt  when the sync or scan behind the issue finished
     */
    public function __construct(
        public EnvironmentIssueType $type,
        public string $message,
        public ?Carbon $occurredAt = null,
    ) {}
}
