<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

it('creates a pending scan, saves its input and queues the submission', function () {
    Storage::fake('voight-lockfiles');
    Bus::fake([SubmitOsvScanJob::class]);
    $environment = Environment::factory()->create();

    $scan = app(OsvScanSubmitter::class)->start(OsvScanType::Locks, '{"files":[]}', ['project_code' => 'p1'], AuditRunTrigger::PostSync, environmentId: $environment->id);

    expect($scan->status)->toBe(OsvScanStatus::Pending)
        ->and($scan->scanner_scan_id)->toBeNull()
        ->and($scan->meta)->toBe(['project_code' => 'p1'])
        ->and($scan->input_path)->toBe("voight/scans/{$scan->id}/input.json");
    Storage::disk('voight-lockfiles')->assertExists($scan->input_path);
    expect(Storage::disk('voight-lockfiles')->get($scan->input_path))->toBe('{"files":[]}');
    Bus::assertDispatched(SubmitOsvScanJob::class, fn ($job) => $job->scan->is($scan));
});

it('leaves no scan behind when its input cannot be saved', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    $environment = Environment::factory()->create();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::shouldReceive('disk')->with('voight-lockfiles')->andReturn($disk);

    expect(fn () => app(OsvScanSubmitter::class)->start(OsvScanType::Locks, '{}', [], AuditRunTrigger::PostSync, environmentId: $environment->id))
        ->toThrow(RuntimeException::class);

    expect(OsvScan::count())->toBe(0);
    Bus::assertNotDispatched(SubmitOsvScanJob::class);
});
