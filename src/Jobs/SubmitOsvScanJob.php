<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;
use Throwable;

class SubmitOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [10, 60, 180];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public OsvScan $scan) {}

    public function handle(OsvScannerClient $client): void
    {
        $scan = $this->scan->refresh();

        if ($scan->status !== OsvScanStatus::Pending) {
            return;
        }

        $input = Storage::disk(FilamentVoight::config()->getLockfilesDisk())->get((string) $scan->input_path);

        if ($input === null) {
            throw new RuntimeException("Scan input {$scan->input_path} is missing");
        }

        $created = $client->createScan($scan->type, $scan->meta, FilamentVoight::config()->getScannerCallbackUrl());

        // Stored before the upload: the upload starts the scan, and its callback
        // must be able to find this row.
        $scan->update(['scanner_scan_id' => $created->scanId]);

        $client->upload($created, $input);

        PollOsvScanJob::dispatch($scan, $created->scanId)
            ->delay(now()->addSeconds(FilamentVoight::config()->getScannerPollInterval()));
    }

    public function failed(Throwable $e): void
    {
        app(OsvScanResultHandler::class)->failForGood($this->scan, 'Submitting the scan failed: ' . $e->getMessage());
    }
}
