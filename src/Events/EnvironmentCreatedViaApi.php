<?php

namespace Statikbe\FilamentVoight\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Statikbe\FilamentVoight\Models\Environment;

class EnvironmentCreatedViaApi
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Environment $environment,
    ) {}
}
