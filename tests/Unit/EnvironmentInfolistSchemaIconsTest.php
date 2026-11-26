<?php

use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas\EnvironmentInfolistSchema;

it('maps known system detail groups to their own icon', function () {
    expect(EnvironmentInfolistSchema::groupIcon('cache'))->toBe(VoightIcon::EnvironmentGroupCache)
        ->and(EnvironmentInfolistSchema::groupIcon('ssl'))->toBe(VoightIcon::EnvironmentGroupSsl);
});

it('falls back to a generic icon for unknown system detail groups', function () {
    expect(EnvironmentInfolistSchema::groupIcon('unknown-group'))->toBe(VoightIcon::EnvironmentGroupFallback);
});
