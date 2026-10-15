<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Services\StrandedScanReaper;

class ReapStrandedScansCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:reap-stranded-scans';

    public $description = 'Fail OSV scans and nightly sweeps that stopped making progress, recording failed audit runs';

    public function handle(StrandedScanReaper $reaper): int
    {
        $this->displayBanner();

        $reaped = $reaper->reap();

        $this->info("Failed {$reaped['scans']} stranded scan(s) and {$reaped['sweeps']} stranded sweep(s).");

        return self::SUCCESS;
    }
}
