<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Services\TagRuleRunner;

class RunTagRulesCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:run-tag-rules';

    public $description = 'Re-run every active auto-tag rule so tags match their saved queries';

    public function handle(TagRuleRunner $runner): int
    {
        $this->displayBanner();

        $runner->runAll();

        $this->info('Auto-tag rules are up to date.');

        return self::SUCCESS;
    }
}
