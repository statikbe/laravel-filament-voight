<?php

namespace Statikbe\FilamentVoight\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;
use Throwable;

/**
 * The polling safety net: checks the scan every poll_interval seconds until it
 * is terminal or the 30-minute deadline passes. Bound to one submission.
 */
class PollOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Unlimited attempts: retryUntil() bounds polling, not a try count. */
    public int $tries = 0;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public OsvScan $scan, public string $scannerScanId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function backoff(): int
    {
        return FilamentVoight::config()->getScannerPollInterval();
    }

    public function handle(OsvScannerClient $client, OsvScanResultHandler $handler): void
    {
        $scan = $this->scan->refresh();

        // Finished elsewhere (callback), or a resubmit replaced this submission.
        if ($scan->status !== OsvScanStatus::Pending || $scan->scanner_scan_id !== $this->scannerScanId) {
            return;
        }

        $status = $client->getScan($this->scannerScanId);

        if ($status->isTerminal()) {
            $handler->handleTerminal($scan, $this->scannerScanId, $status);

            return;
        }

        $this->release(FilamentVoight::config()->getScannerPollInterval());
    }

    public function failed(Throwable $e): void
    {
        // retryUntil() passing surfaces as MaxAttemptsExceededException; a job
        // timeout is a subclass of it but is not the deadline.
        $message = $e instanceof MaxAttemptsExceededException && ! $e instanceof TimeoutExceededException
            ? 'poll deadline exceeded'
            : 'Polling the scan failed: ' . $e->getMessage();

        app(OsvScanResultHandler::class)->failForGood($this->scan, $message, $this->scannerScanId);
    }
}
