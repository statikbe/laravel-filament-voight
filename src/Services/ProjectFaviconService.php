<?php

namespace Statikbe\FilamentVoight\Services;

use AshAllenDesign\FaviconFetcher\Concerns\MakesHttpRequests;
use AshAllenDesign\FaviconFetcher\Exceptions\FaviconFetcherException;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use AshAllenDesign\FaviconFetcher\Favicon as FetchedFavicon;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Symfony\Component\DomCrawler\Crawler;

class ProjectFaviconService
{
    use MakesHttpRequests;

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
            $storedPath = $this->storeFirstUsableFavicon($project, $sourceUrl);
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
     * Some sites answer 200 with an empty file (e.g. a placeholder /favicon.ico),
     * so walk the candidates and keep the first one that has content.
     *
     * @throws FaviconFetcherException
     */
    private function storeFirstUsableFavicon(Project $project, string $url): ?string
    {
        $config = FilamentVoight::config();
        $disk = Storage::disk($config->getFaviconsDisk());

        foreach ($this->candidates($url) as $favicon) {
            // Timestamped filename so browsers don't keep showing a cached old icon.
            $path = $favicon->storeAs(
                $config->getFaviconsDirectory(),
                $project->getKey() . '-' . now()->timestamp,
                $config->getFaviconsDisk(),
            );

            if ($disk->size($path) > 0) {
                return $path;
            }

            $disk->delete($path);
        }

        return null;
    }

    /**
     * Icons advertised by the site, largest first, then the configured fallback drivers.
     *
     * @return Generator<FetchedFavicon>
     *
     * @throws FaviconFetcherException
     */
    private function candidates(string $url): Generator
    {
        $advertised = $this->advertisedFavicons($url);

        // fetchAll guesses /favicon.ico when the page advertises nothing usable.
        yield from ($advertised->isNotEmpty() ? $advertised : Favicon::fetchAll($url))
            ->sortByDesc(fn (FetchedFavicon $favicon): int => $favicon->getIconSize() ?? 0)
            ->values();

        foreach (FilamentVoight::config()->getFaviconFallbackDrivers() as $driver) {
            $favicon = Favicon::driver($driver)->fetch($url);

            if ($favicon) {
                yield $favicon;
            }
        }
    }

    /**
     * Icon tags from the page's head, whatever its status code: dev locks, basic auth
     * and error pages usually still carry the site's icons, but favicon-fetcher ignores
     * any non-2xx page. Only icons that themselves load are kept, so an error page is
     * never stored as the icon.
     *
     * @return Collection<int, FetchedFavicon>
     *
     * @throws FaviconFetcherException
     */
    private function advertisedFavicons(string $url): Collection
    {
        $response = $this->withRequestExceptionHandling(fn (): Response => $this->httpClient()->get($url));
        $pageUrl = (string) ($response->effectiveUri() ?? $url);

        return collect((new Crawler($response->body(), $pageUrl))
            ->filter('head link[rel="icon"][href], head link[rel="shortcut icon"][href], head link[rel="apple-touch-icon"][href]')
            ->each(function (Crawler $linkTag) use ($url): FetchedFavicon {
                $favicon = new FetchedFavicon($url, $linkTag->link()->getUri());

                if ($iconSize = $linkTag->attr('sizes')) {
                    $favicon->setIconSize((int) $iconSize);
                }

                return $favicon->setIconType(match ($linkTag->attr('rel')) {
                    'icon' => FetchedFavicon::TYPE_ICON,
                    'shortcut icon' => FetchedFavicon::TYPE_SHORTCUT_ICON,
                    'apple-touch-icon' => FetchedFavicon::TYPE_APPLE_TOUCH_ICON,
                    default => FetchedFavicon::TYPE_ICON_UNKNOWN,
                });
            }))
            ->filter(fn (FetchedFavicon $favicon): bool => $this->withRequestExceptionHandling(
                fn (): bool => $this->httpClient()->get($favicon->getFaviconUrl())->successful()
            ))
            ->values();
    }
}
