<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Services\TagRuleRunner;

/**
 * Re-runs all active auto-tag rules. Unique until it starts, so a burst of changes queues one run,
 * and changes made while it runs queue the next.
 */
class RunTagRulesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * Queues a run when any rule is active; a cheap check, as it runs on every save of a taggable record.
     */
    public static function dispatchIfRulesExist(): void
    {
        if (FilamentVoight::config()->getTagRuleModel()::query()->active()->exists()) {
            self::dispatch();
        }
    }

    public function handle(TagRuleRunner $runner): void
    {
        $runner->runAll();
    }
}
