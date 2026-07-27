<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

function voightNightlyEvents(): Collection
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'voight:run-osv-scan --nightly'));
}

it('schedules the nightly scan using the configured cron expression', function () {
    config()->set('filament-voight.scanner.nightly_cron', '30 3 * * *');
    app()->forgetInstance(Schedule::class);

    $events = voightNightlyEvents();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 3 * * *');
});

it('defaults to midnight daily', function () {
    config()->set('filament-voight.scanner.nightly_cron', '0 0 * * *');
    app()->forgetInstance(Schedule::class);

    expect(voightNightlyEvents()->first()->expression)->toBe('0 0 * * *');
});

it('does not schedule the nightly scan when the cron is empty', function () {
    config()->set('filament-voight.scanner.nightly_cron', '');
    app()->forgetInstance(Schedule::class);

    expect(voightNightlyEvents())->toHaveCount(0);
});
