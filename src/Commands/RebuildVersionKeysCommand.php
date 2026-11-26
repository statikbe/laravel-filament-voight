<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Services\VersionKeyRebuilder;

class RebuildVersionKeysCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:rebuild-version-keys';

    public $description = 'Recompute the sortable version keys used by version filters and sorting';

    public function handle(VersionKeyRebuilder $rebuilder): int
    {
        $this->displayBanner();

        foreach ($rebuilder->rebuild() as $table => $count) {
            $this->line("  {$table}: {$count} updated");
        }

        return self::SUCCESS;
    }
}
