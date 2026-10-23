<?php

namespace Statikbe\FilamentVoight\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A host policy for the vulnerability model that denies listing.
 */
class DenyAllVulnerabilitiesPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return false;
    }
}
