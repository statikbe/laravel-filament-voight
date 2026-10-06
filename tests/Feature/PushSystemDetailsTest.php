<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('filament-voight.push', ['base_url' => null, 'token' => null, 'environment' => null]);
});

it('skips quietly when no base url is configured', function () {
    Http::fake();

    $this->artisan('voight:push-system-details')->assertSuccessful();

    Http::assertNothingSent();
});

it('refuses a non-https base url on a remote host', function () {
    Http::fake();
    config()->set('filament-voight.push.base_url', 'http://voight.example.com');

    $this->artisan('voight:push-system-details')->assertFailed();

    Http::assertNothingSent();
});

it('posts the snapshot with the bearer token', function () {
    Http::fake(['*' => Http::response(null, 204)]);
    config()->set('filament-voight.push', ['base_url' => 'https://voight.example.com/', 'token' => 'secret', 'environment' => null]);

    $this->artisan('voight:push-system-details')->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://voight.example.com/api/voight/system-details'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['environment'] === app()->environment()
            && filled($request['collected_at'])
            && $request['versions']['php'] === PHP_VERSION
            && array_key_exists('laravel', $request['versions'])
            && $request['server']['php']['version'] === PHP_VERSION
            && in_array('json', $request['server']['php']['extensions'], true)
            && $request['server']['php']['sapi'] === 'cli'
            && array_key_exists('memory_limit', $request['server']['php'])
            && array_key_exists('commit', $request['server']['deployment'])
            && isset($request['server']['system']['os'])
            && array_key_exists('laravel', $request->data());
    });
});

it('lets the option override the configured environment, url and token', function () {
    Http::fake(['*' => Http::response(null, 204)]);
    config()->set('filament-voight.push', ['base_url' => 'https://voight.example.com', 'token' => 'secret', 'environment' => 'staging']);

    $this->artisan('voight:push-system-details')->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['environment'] === 'staging');

    $this->artisan('voight:push-system-details', ['--environment' => 'production', '--url' => 'http://localhost:8000', '--token' => 'other'])
        ->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://localhost:8000/api/voight/system-details'
        && $request['environment'] === 'production'
        && $request->hasHeader('Authorization', 'Bearer other'));
});

it('fails on a non-2xx response', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    config()->set('filament-voight.push', ['base_url' => 'https://voight.example.com', 'token' => 'bad', 'environment' => null]);

    $this->artisan('voight:push-system-details')
        ->expectsOutputToContain('401')
        ->assertFailed();
});

it('uses the web-server snapshot from a signed loopback request when it works', function () {
    $web = ['collected_at' => '2026-10-06T09:00:00+00:00', 'versions' => ['php' => '8.4.0'], 'server' => ['php' => ['sapi' => 'fpm-fcgi']], 'laravel' => null];
    Http::fake([
        'http://loopback.test/*' => Http::response($web),
        '*' => Http::response(null, 204),
    ]);
    config()->set('filament-voight.push', ['base_url' => 'https://voight.example.com', 'token' => 'secret', 'environment' => null, 'loopback_url' => 'http://loopback.test']);

    $this->artisan('voight:push-system-details')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://loopback.test/api/voight/system-details/collect?')
        && str_contains($request->url(), 'signature='));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://voight.example.com/api/voight/system-details'
        && $request['server']['php']['sapi'] === 'fpm-fcgi');
});

it('warns and falls back to CLI values when the loopback fails', function () {
    Http::fake([
        'http://loopback.test/*' => Http::response('nope', 500),
        '*' => Http::response(null, 204),
    ]);
    config()->set('filament-voight.push', ['base_url' => 'https://voight.example.com', 'token' => 'secret', 'environment' => null, 'loopback_url' => 'http://loopback.test']);

    $this->artisan('voight:push-system-details')
        ->expectsOutputToContain('using CLI values')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://voight.example.com/api/voight/system-details'
        && $request['server']['php']['sapi'] === PHP_SAPI);
});

it('schedules the push daily only when a base url is set', function () {
    $events = fn () => collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'voight:push-system-details'));

    app()->forgetInstance(Schedule::class);
    expect($events())->toHaveCount(0);

    config()->set('filament-voight.push.base_url', 'https://voight.example.com');
    app()->forgetInstance(Schedule::class);
    expect($events())->toHaveCount(1);
});
