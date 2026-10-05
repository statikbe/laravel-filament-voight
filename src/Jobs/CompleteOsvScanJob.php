<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;

/**
 * Queued by the scanner callback. The callback only says "look now": the status
 * is always re-read from the authenticated API, so a forged callback is harmless.
 */
class CompleteOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [10, 30, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public OsvScan $scan, public string $scannerScanId) {}

    public function handle(OsvScannerClient $client, OsvScanResultHandler $handler): void
    {
        $scan = $this->scan->refresh();

        if ($scan->status !== OsvScanStatus::Pending || $scan->scanner_scan_id !== $this->scannerScanId) {
            return;
        }

        $status = $client->getScan($this->scannerScanId);

        // Not terminal yet: the poller keeps watching.
        if ($status->isTerminal()) {
            $handler->handleTerminal($scan, $this->scannerScanId, $status);
        }
    }
}
