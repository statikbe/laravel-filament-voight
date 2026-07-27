<?php

use Illuminate\Support\Facades\Queue;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Jobs\RunNightlyOsvScanJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Models\Environment;

it('dispatches the nightly sweep with --nightly', function () {
    Queue::fake();
    Environment::factory()->create();

    $this->artisan('voight:run-osv-scan --nightly')->assertSuccessful();

    Queue::assertPushed(RunNightlyOsvScanJob::class);
    Queue::assertNotPushed(RunOsvScanJob::class);
});

it('dispatches per-environment manual scans without --nightly', function () {
    Queue::fake();
    config()->set('filament-voight.scanner.url', 'https://scanner.test/locks');
    Environment::factory()->create();

    $this->artisan('voight:run-osv-scan')->assertSuccessful();

    Queue::assertPushed(RunOsvScanJob::class, fn ($job) => $job->trigger === AuditRunTrigger::Manual);
    Queue::assertNotPushed(RunNightlyOsvScanJob::class);
});
