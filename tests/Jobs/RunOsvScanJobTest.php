<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;

function createScannableEnvironment(): Environment
{
    $environment = Environment::factory()->create();

    $lockfilePath = 'test-project/production/composer.lock';
    Storage::disk('voight-lockfiles')->put($lockfilePath, json_encode([
        'packages' => [],
        'packages-dev' => [],
    ]));

    DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => [$lockfilePath],
        'status' => DependencySyncStatus::Completed,
    ]);

    return $environment;
}

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scan');
    Bus::fake([SendAuditAlertsJob::class]);
});

it('dispatches the alerts job with the created run when the scan completes', function () {
    Http::fake(['scanner.test/*' => Http::response(['results' => []])]);

    $environment = createScannableEnvironment();

    RunOsvScanJob::dispatchSync($environment);

    $run = AuditRun::sole();
    expect($run->status)->toBe(AuditRunStatus::Completed);

    Bus::assertDispatched(
        SendAuditAlertsJob::class,
        fn (SendAuditAlertsJob $job): bool => $job->auditRun->is($run),
    );
});

it('does not dispatch the alerts job when the scanner fails', function () {
    Http::fake(['scanner.test/*' => Http::response('scanner exploded', 500)]);

    $environment = createScannableEnvironment();

    expect(fn () => RunOsvScanJob::dispatchSync($environment))->toThrow(RuntimeException::class);

    expect(AuditRun::sole()->status)->toBe(AuditRunStatus::Failed);
    Bus::assertNotDispatched(SendAuditAlertsJob::class);
});
