<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Services\ProjectFaviconService;

beforeEach(function () {
    Storage::fake('public');
});

function fakeSite(string $html, array $assets = [], int $status = 200, string $missingBody = ''): void
{
    Http::fake(function (Request $request) use ($html, $assets, $status, $missingBody) {
        $url = rtrim($request->url(), '/');

        if ($url === 'https://site.test') {
            return Http::response($html, $status, ['Content-Type' => 'text/html']);
        }

        return array_key_exists($url, $assets)
            ? Http::response($assets[$url], 200)
            : Http::response($missingBody, 404);
    });
}

it('prefers the production environment url', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'staging', 'url' => 'https://staging.site.test']);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->resolveSourceUrl($project))->toBe('https://site.test');
});

it('treats any production-stage name as production', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'acceptance', 'url' => 'https://acc.site.test']);
    Environment::factory()->for($project)->create(['name' => 'live', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->resolveSourceUrl($project))->toBe('https://site.test');
});

it('falls back to any environment with a url', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'staging', 'url' => 'https://staging.site.test']);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => null]);

    expect(app(ProjectFaviconService::class)->resolveSourceUrl($project))->toBe('https://staging.site.test');
});

it('stores the largest icon advertised by the site', function () {
    fakeSite(
        '<html><head>'
        . '<link rel="icon" href="/favicon-32.png" sizes="32x32">'
        . '<link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">'
        . '</head></html>',
        [
            'https://site.test/favicon-32.png' => pngIcon(32),
            'https://site.test/apple-touch-icon.png' => pngIcon(180),
        ],
    );

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    $found = app(ProjectFaviconService::class)->fetch($project);

    $project->refresh();
    expect($found)->toBeTrue()
        ->and($project->favicon_path)->toStartWith('voight/favicons/' . $project->id . '-')
        ->and($project->favicon_path)->toEndWith('.png')
        ->and($project->favicon_fetched_at)->not->toBeNull();
    Storage::disk('public')->assertExists($project->favicon_path);
    expect(Storage::disk('public')->get($project->favicon_path))->toBe(pngIcon(180));
});

it('reads icon tags from an error page such as a dev lock', function (int $status) {
    fakeSite(
        '<html><head><link rel="icon" href="/favicon/icon-32.png" sizes="32x32"></head></html>',
        ['https://site.test/favicon/icon-32.png' => pngIcon(32)],
        status: $status,
    );

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue()
        ->and(Storage::disk('public')->get($project->refresh()->favicon_path))->toBe(pngIcon(32));
})->with([401, 403, 404, 503]);

it('skips an advertised icon that answers with an error page', function () {
    fakeSite(
        '<html><head>'
        . '<link rel="apple-touch-icon" href="/missing.png" sizes="180x180">'
        . '<link rel="icon" href="/icon-32.png" sizes="32x32">'
        . '</head></html>',
        ['https://site.test/icon-32.png' => pngIcon(32)],
        missingBody: '<html>Not found</html>',
    );

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue()
        ->and(Storage::disk('public')->get($project->refresh()->favicon_path))->toBe(pngIcon(32));
});

it('logs in with the configured basic auth credentials', function () {
    config()->set('filament-voight.favicons.basic_auth', ['username' => 'statik', 'password' => 'secret']);
    $authorization = 'Basic ' . base64_encode('statik:secret');

    Http::fake(function (Request $request) use ($authorization) {
        if ($request->header('Authorization') !== [$authorization]) {
            return Http::response('<html><head></head></html>', 401, ['WWW-Authenticate' => 'Basic realm="site"']);
        }

        return match (rtrim($request->url(), '/')) {
            'https://site.test' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>'),
            'https://site.test/icon.png' => Http::response(pngIcon()),
            default => Http::response('', 404),
        };
    });

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue()
        ->and(Storage::disk('public')->get($project->refresh()->favicon_path))->toBe(pngIcon());
});

it('never sends the basic auth credentials to another host', function () {
    config()->set('filament-voight.favicons.basic_auth', ['username' => 'statik', 'password' => 'secret']);
    fakeSite('<html><head><link rel="icon" href="https://cdn.test/icon.png"></head></html>');
    Http::fake(['cdn.test/*' => Http::response('cdn')]);

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    app(ProjectFaviconService::class)->fetch($project);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://site.test' && $request->hasHeader('Authorization'));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://cdn.test') && $request->hasHeader('Authorization'));
});

it('sends no credentials when basic auth is not configured', function () {
    fakeSite('<html><head><link rel="icon" href="/icon.png"></head></html>', ['https://site.test/icon.png' => 'png']);

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    app(ProjectFaviconService::class)->fetch($project);

    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
});

it('falls back to /favicon.ico when the page has no icon tags', function () {
    fakeSite('<html><head></head></html>', ['https://site.test/favicon.ico' => icoIcon()]);

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue()
        ->and($project->refresh()->favicon_path)->toEndWith('.ico');
});

it('skips an empty icon and stores the next one', function () {
    fakeSite(
        '<html><head>'
        . '<link rel="apple-touch-icon" href="/empty.png" sizes="180x180">'
        . '<link rel="icon" href="/icon-32.png" sizes="32x32">'
        . '</head></html>',
        [
            'https://site.test/empty.png' => '',
            'https://site.test/icon-32.png' => pngIcon(32),
        ],
    );

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue();
    expect(Storage::disk('public')->get($project->refresh()->favicon_path))->toBe(pngIcon(32))
        ->and(Storage::disk('public')->allFiles('voight/favicons'))->toHaveCount(1);
});

it('never stores an svg icon, whatever its url or content type says', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    Http::fake(fn (Request $request) => match (rtrim($request->url(), '/')) {
        'https://site.test' => Http::response('<html><head><link rel="icon" href="/icon.svg"><link rel="icon" href="/icon.png"></head></html>'),
        'https://site.test/icon.svg' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml']),
        'https://site.test/icon.png' => Http::response($svg, 200, ['Content-Type' => 'image/png']),
        default => Http::response('', 404),
    });

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeFalse()
        ->and(Storage::disk('public')->allFiles('voight/favicons'))->toBeEmpty();
});

it('names the stored file after the detected image type, not the url', function () {
    fakeSite('<html><head><link rel="icon" href="/icon.svg"></head></html>', ['https://site.test/icon.svg' => pngIcon()]);

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue()
        ->and($project->refresh()->favicon_path)->toEndWith('.png');
});

it('does not request an advertised icon with a non-http url', function () {
    fakeSite('<html><head><link rel="icon" href="ftp://site.test/icon.png"></head></html>', ['https://site.test/favicon.ico' => icoIcon()]);

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeTrue()
        ->and($project->refresh()->favicon_path)->toEndWith('.ico');
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'ftp://'));
});

it('stores nothing when every icon is empty', function () {
    fakeSite('<html><head></head></html>', ['https://site.test/favicon.ico' => '']);

    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeFalse()
        ->and($project->refresh()->favicon_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles('voight/favicons'))->toBeEmpty();
});

it('keeps the previous favicon when the site is unreachable', function () {
    Http::fake(['*' => Http::response('', 500)]);
    Storage::disk('public')->put('voight/favicons/old.png', 'old');

    $project = Project::factory()->create(['favicon_path' => 'voight/favicons/old.png']);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeFalse();

    $project->refresh();
    expect($project->favicon_path)->toBe('voight/favicons/old.png')
        ->and($project->favicon_fetched_at)->not->toBeNull();
    Storage::disk('public')->assertExists('voight/favicons/old.png');
});

it('deletes the previous favicon after storing a new one', function () {
    fakeSite('<html><head><link rel="icon" href="/icon.png"></head></html>', ['https://site.test/icon.png' => pngIcon()]);
    Storage::disk('public')->put('voight/favicons/old.png', 'old');

    $project = Project::factory()->create(['favicon_path' => 'voight/favicons/old.png']);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    app(ProjectFaviconService::class)->fetch($project);

    Storage::disk('public')->assertMissing('voight/favicons/old.png');
    Storage::disk('public')->assertExists($project->refresh()->favicon_path);
});

it('does nothing when no environment has a url', function () {
    Http::fake();
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production']);

    expect(app(ProjectFaviconService::class)->fetch($project))->toBeFalse()
        ->and($project->refresh()->favicon_fetched_at)->toBeNull();
    Http::assertNothingSent();
});

it('is due when never fetched or older than the refresh window', function () {
    $service = app(ProjectFaviconService::class);

    expect($service->isDue(Project::factory()->make(['favicon_fetched_at' => null])))->toBeTrue()
        ->and($service->isDue(Project::factory()->make(['favicon_fetched_at' => now()->subDays(31)])))->toBeTrue()
        ->and($service->isDue(Project::factory()->make(['favicon_fetched_at' => now()->subDay()])))->toBeFalse();
});

it('is never due when favicon fetching is disabled', function () {
    config()->set('filament-voight.favicons.enabled', false);

    expect(app(ProjectFaviconService::class)->isDue(Project::factory()->make()))->toBeFalse();
});
