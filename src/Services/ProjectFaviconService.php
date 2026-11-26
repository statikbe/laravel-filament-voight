<?php

namespace Statikbe\FilamentVoight\Services;

use AshAllenDesign\FaviconFetcher\Concerns\MakesHttpRequests;
use AshAllenDesign\FaviconFetcher\Exceptions\FaviconFetcherException;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use AshAllenDesign\FaviconFetcher\Favicon as FetchedFavicon;
use Generator;
use Illuminate\Http\Client\PendingRequest;
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
     * Downloads the icons itself rather than through favicon-fetcher, so the site's
     * basic auth applies. Only http(s) URLs are fetched and only raster images are
     * kept: icons are served from the app's own domain, where an SVG could run script.
     * Error pages and empty files (e.g. a placeholder /favicon.ico) are skipped too.
     *
     * @throws FaviconFetcherException
     */
    private function storeFirstUsableFavicon(Project $project, string $url): ?string
    {
        $config = FilamentVoight::config();

        foreach ($this->candidates($url) as $favicon) {
            $iconUrl = $favicon->getFaviconUrl();

            if (! $this->isHttpUrl($iconUrl)) {
                continue;
            }

            $response = $this->download($iconUrl, $url);
            $extension = $response === null ? null : $this->rasterImageExtension($response->body());

            if ($extension === null) {
                continue;
            }

            // Timestamped filename so browsers don't keep showing a cached old icon.
            $path = $config->getFaviconsDirectory() . '/' . $project->getKey() . '-' . now()->timestamp . '.' . $extension;

            Storage::disk($config->getFaviconsDisk())->put($path, $response->body());

            return $path;
        }

        return null;
    }

    /**
     * Icons advertised by the site, largest first, then /favicon.ico, then the
     * configured fallback drivers.
     *
     * @return Generator<FetchedFavicon>
     *
     * @throws FaviconFetcherException
     */
    private function candidates(string $url): Generator
    {
        yield from $this->advertisedFavicons($url)
            ->sortByDesc(fn (FetchedFavicon $favicon): int => $favicon->getIconSize() ?? 0)
            ->values();

        $root = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

        yield new FetchedFavicon($url, $root . '/favicon.ico');

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
     * any non-2xx page.
     *
     * @return Collection<int, FetchedFavicon>
     *
     * @throws FaviconFetcherException
     */
    private function advertisedFavicons(string $url): Collection
    {
        $response = $this->withRequestExceptionHandling(fn (): Response => $this->clientFor($url, $url)->get($url));
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
            }));
    }

    /**
     * A successful response for the icon, or null when it errors or can't be reached,
     * so one broken icon doesn't stop the next candidate from being tried.
     */
    private function download(string $iconUrl, string $siteUrl): ?Response
    {
        try {
            $response = $this->withRequestExceptionHandling(fn (): Response => $this->clientFor($iconUrl, $siteUrl)->get($iconUrl));
        } catch (FaviconFetcherException) {
            return null;
        }

        return $response->successful() ? $response : null;
    }

    /**
     * favicon-fetcher's configured client (timeouts, user agent, TLS), plus the
     * configured basic auth, but only for requests to the project's own site.
     */
    private function clientFor(string $requestUrl, string $siteUrl): PendingRequest
    {
        $client = $this->httpClient();
        $basicAuth = FilamentVoight::config()->getFaviconBasicAuth();

        if ($basicAuth !== null && parse_url($requestUrl, PHP_URL_HOST) === parse_url($siteUrl, PHP_URL_HOST)) {
            $client->withBasicAuth($basicAuth['username'], $basicAuth['password']);
        }

        return $client;
    }

    private function isHttpUrl(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * The extension for the image type detected from the bytes themselves, or null
     * when they are not a raster image (SVG, HTML, empty...). The URL and the
     * Content-Type header are not trusted.
     */
    private function rasterImageExtension(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $imageInfo = @getimagesizefromstring($body);

        return match ($imageInfo === false ? null : $imageInfo[2]) {
            IMAGETYPE_ICO => 'ico',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
    }
}
