<?php

namespace Statikbe\FilamentVoight\Services;

use AshAllenDesign\FaviconFetcher\Exceptions\FaviconFetcherException;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use AshAllenDesign\FaviconFetcher\Favicon as FetchedFavicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

class ProjectFaviconService
{
    public function isDue(Project $project): bool
    {
        if (! FilamentVoight::config()->isFaviconFetchingEnabled()) {
            return false;
        }

        if ($project->favicon_fetched_at === null) {
            return true;
        }

        return $project->favicon_fetched_at->lte(now()->subDays(FilamentVoight::config()->getFaviconRefreshAfterDays()));
    }

    /**
     * Prefer a production-stage environment (see `environments.stages`), then fall
     * back to whichever environment reported a URL most recently. Non-production
     * sites are often behind basic auth.
     */
    public function resolveSourceUrl(Project $project): ?string
    {
        $environments = $project->environments()
            ->whereNotNull('url')
            ->latest('updated_at')
            ->get();

        $production = $environments->first(
            fn (Environment $environment): bool => EnvironmentStage::fromName($environment->name) === EnvironmentStage::Production
        );

        return ($production ?? $environments->first())?->url;
    }

    /**
     * Fetch and store the project's favicon. A failed fetch keeps the
     * previous icon so a site that is briefly down doesn't lose it.
     */
    public function fetch(Project $project): bool
    {
        $sourceUrl = $this->resolveSourceUrl($project);

        if ($sourceUrl === null) {
            return false;
        }

        $config = FilamentVoight::config();
        $previousPath = $project->favicon_path;
        $storedPath = null;

        try {
            $favicon = $this->findFavicon($sourceUrl);

            if ($favicon) {
                // Timestamped filename so browsers don't keep showing a cached old icon.
                $storedPath = $favicon->storeAs(
                    $config->getFaviconsDirectory(),
                    $project->getKey() . '-' . now()->timestamp,
                    $config->getFaviconsDisk(),
                );
            }
        } catch (FaviconFetcherException $exception) {
            Log::info('[Voight] Favicon fetch failed', [
                'project' => $project->project_code,
                'url' => $sourceUrl,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($storedPath && $previousPath && $previousPath !== $storedPath) {
            Storage::disk($config->getFaviconsDisk())->delete($previousPath);
        }

        $project->forceFill([
            'favicon_path' => $storedPath ?? $previousPath,
            'favicon_fetched_at' => now(),
        ])->save();

        return $storedPath !== null;
    }

    /**
     * @throws FaviconFetcherException
     */
    private function findFavicon(string $url): ?FetchedFavicon
    {
        // fetchAll also picks up apple-touch-icon, which is usually the sharpest option.
        $favicon = Favicon::fetchAll($url)->largest();

        if ($favicon) {
            return $favicon;
        }

        foreach (FilamentVoight::config()->getFaviconFallbackDrivers() as $driver) {
            $favicon = Favicon::driver($driver)->fetch($url);

            if ($favicon) {
                return $favicon;
            }
        }

        return null;
    }
}
