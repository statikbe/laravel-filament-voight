<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Services\ProjectFaviconService;

class FetchProjectFaviconJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    // favicon-fetcher ships without an HTTP timeout, so cap the whole job instead.
    public int $timeout = 60;

    public function __construct(
        public Project $project,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->project->getKey();
    }

    public function handle(ProjectFaviconService $service): void
    {
        $service->fetch($this->project);
    }
}
