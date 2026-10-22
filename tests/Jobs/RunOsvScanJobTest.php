<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
    config()->set('filament-voight.scanner.callback_url', '');
});

function environmentWithLockfiles(array $files = ['p/production/composer.lock' => '{"packages":[]}']): Environment
{
    $environment = Environment::factory()->create();

    foreach ($files as $path => $contents) {
        Storage::disk('voight-lockfiles')->put($path, $contents);
    }

    DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => array_keys($files),
        'status' => DependencySyncStatus::Completed,
    ]);

    return $environment;
}

it('starts a locks scan with the environment\'s lockfiles base64-encoded', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    $environment = environmentWithLockfiles([
        'p/production/composer.lock' => '{"packages":[]}',
        'p/production/composer.json' => '{}', // not a lockfile: never sent
        'p/production/yarn.lock' => 'lockfile v1',
    ]);

    RunOsvScanJob::dispatchSync($environment, AuditRunTrigger::PostSync);

    $scan = OsvScan::sole();
    expect($scan->type)->toBe(OsvScanType::Locks)
        ->and($scan->environment_id)->toBe($environment->id)
        ->and($scan->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and($scan->meta)->toBe(['project_code' => $environment->project->project_code, 'environment' => $environment->name]);

    $input = json_decode(Storage::disk('voight-lockfiles')->get($scan->input_path), true);
    expect($input['files'])->toBe([
        ['name' => 'composer.lock', 'content_base64' => base64_encode('{"packages":[]}')],
        ['name' => 'yarn.lock', 'content_base64' => base64_encode('lockfile v1')],
    ]);
    Bus::assertDispatched(SubmitOsvScanJob::class);
});

it('defaults the trigger to manual', function () {
    Bus::fake([SubmitOsvScanJob::class]);

    RunOsvScanJob::dispatchSync(environmentWithLockfiles());

    expect(OsvScan::sole()->trigger)->toBe(AuditRunTrigger::Manual);
});

it('fails without starting a scan when there is no completed sync', function () {
    Bus::fake([SubmitOsvScanJob::class]);

    RunOsvScanJob::dispatchSync(Environment::factory()->create());

    expect(OsvScan::count())->toBe(0);
    Bus::assertNotDispatched(SubmitOsvScanJob::class);
});

it('records findings end to end through submit, poll and completion', function () {
    Bus::fake([SendAuditAlertsJob::class]);
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $environment = environmentWithLockfiles();
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    fakeScanner([
        'findings' => [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']],
        'vulnerabilities' => ['GHSA-5vg9' => ['id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
        'summary' => [],
    ]);

    // The sync queue runs submit → poll → completion inline (delays are ignored).
    RunOsvScanJob::dispatchSync($environment, AuditRunTrigger::PostSync);

    $run = AuditRun::sole();
    expect($run->status)->toBe(AuditRunStatus::Completed)
        ->and($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1)
        ->and(OsvScan::sole()->status)->toBe(OsvScanStatus::Completed);
    Bus::assertDispatched(SendAuditAlertsJob::class, fn ($job) => $job->auditRun->is($run));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['callback_url'] === null);
});

it('records one failed run when the scanner rejects the input', function () {
    fakeScanner(status: 'failed', error: ['code' => 'invalid_input', 'message' => 'unsupported lockfile name: x', 'retryable' => false]);

    RunOsvScanJob::dispatchSync(environmentWithLockfiles());

    $run = AuditRun::sole();
    expect($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->error_message)->toBe('invalid_input: unsupported lockfile name: x');
});

it('records a finding for a vulnerable npm version installed only in a nested node_modules', function () {
    Bus::fake([SendAuditAlertsJob::class]);
    fakeScanner([
        'findings' => [['ecosystem' => 'npm', 'name' => 'ms', 'version' => '2.0.0', 'vulnerability_id' => 'GHSA-w9mr', 'max_severity' => '5.3']],
        'vulnerabilities' => ['GHSA-w9mr' => ['id' => 'GHSA-w9mr', 'summary' => 'ms ReDoS', 'database_specific' => ['severity' => 'MODERATE'],
            'affected' => [['package' => ['name' => 'ms'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '2.0.0-fixed']]]]]]]],
        'summary' => [],
    ]);

    $environment = Environment::factory()->create();
    Storage::disk('voight-lockfiles')->put('p/production/package-lock.json', lockfileFixture('npm/package-lock.json'));
    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['p/production/package-lock.json'],
        'status' => DependencySyncStatus::Pending,
    ]);

    // The sync chains the post-sync scan, which now runs submit → poll → completion inline.
    ProcessLockFilesJob::dispatchSync($sync);

    $finding = AuditFinding::sole();
    expect($finding->package->name)->toBe('ms')
        ->and($finding->installed_version)->toBe('2.0.0')
        ->and($finding->auditRun->environment_id)->toBe($environment->id);
});

it('records a finding for a composer package whose lockfile version has a v prefix', function () {
    // The scanner reports the version as written in composer.lock; the parser stores it without the "v".
    Bus::fake([SendAuditAlertsJob::class]);
    fakeScanner([
        'findings' => [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0', 'vulnerability_id' => 'GHSA-78fx', 'max_severity' => '7.5']],
        'vulnerabilities' => ['GHSA-78fx' => ['id' => 'GHSA-78fx', 'summary' => 'framework issue', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
        'summary' => [],
    ]);

    $environment = Environment::factory()->create();
    Storage::disk('voight-lockfiles')->put('p/production/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework', 'version' => 'v10.9.0']],
    ]));
    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['p/production/composer.lock'],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync);

    $finding = AuditFinding::sole();
    expect($finding->package->name)->toBe('laravel/framework')
        ->and($finding->installed_version)->toBe('10.9.0');
});

it('records one failed run once starting the scan has failed on every try', function () {
    $environment = environmentWithLockfiles();

    (new RunOsvScanJob($environment, AuditRunTrigger::PostSync))->failed(new RuntimeException('database unavailable'));

    expect(AuditRun::sole())
        ->environment_id->toBe($environment->id)
        ->status->toBe(AuditRunStatus::Failed)
        ->trigger->toBe(AuditRunTrigger::PostSync)
        ->error_message->toBe('Starting the scan failed: database unavailable');
});

it('records a failed run when the submitter keeps throwing through the queue', function () {
    $this->mock(OsvScanSubmitter::class)->shouldReceive('start')->andThrow(new RuntimeException('disk full'));

    expect(fn () => RunOsvScanJob::dispatchSync(environmentWithLockfiles()))->toThrow(RuntimeException::class, 'disk full');

    expect(AuditRun::sole()->error_message)->toBe('Starting the scan failed: disk full');
});

it('records no failed run when there is no completed sync to scan', function () {
    RunOsvScanJob::dispatchSync(Environment::factory()->create());

    expect(AuditRun::count())->toBe(0);
});
