<?php

namespace Statikbe\FilamentVoight\Resources\Concerns;

use Livewire\Attributes\Url;

/**
 * A list page whose filtered table can be saved as an auto-tag rule; "Open in table" passes the rule it came from.
 */
trait SavesTagRules
{
    #[Url(as: 'tag_rule')]
    public ?string $tagRule = null;
}
