<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Statikbe\FilamentVoight\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Raw contents of a lockfile fixture under tests/Fixtures/lockfiles.
 */
function lockfileFixture(string $path): string
{
    return file_get_contents(__DIR__ . '/Fixtures/lockfiles/' . $path);
}

/**
 * Index parsed packages by their key.
 *
 * @param  array<int, array<string, mixed>>  $packages
 * @return array<string, array<string, mixed>>
 */
function byKey(array $packages): array
{
    return collect($packages)->keyBy('key')->all();
}

/**
 * The keys a parsed package depends on, sorted.
 *
 * @param  array<string, mixed>  $package
 * @return array<int, string>
 */
function dependencyKeys(array $package): array
{
    return collect($package['dependencies'])->pluck('key')->sort()->values()->all();
}

/**
 * Fake the async OSV scanner: POST /scans (distinct ids per call), the
 * presigned upload, GET /scans/{id} with the given status, and the result
 * download. Scanner URL must be https://scanner.test/scans.
 *
 * @param  array<string, mixed>  $result  result.json body
 * @param  array<string, mixed>|null  $error  error object for status=failed
 */
function fakeScanner(array $result = ['findings' => [], 'vulnerabilities' => [], 'summary' => []], string $status = 'done', ?array $error = null): void
{
    $counter = 0;

    Http::fake(function (Request $request) use (&$counter, $result, $status, $error) {
        $url = $request->url();

        if ($request->method() === 'POST' && $url === 'https://scanner.test/scans') {
            $counter++;
            $id = '01J9ZK3M4N5P6R7S8T9V0W' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);

            return Http::response([
                'scan_id' => $id,
                'upload' => ['url' => "https://s3.test/upload/{$id}", 'method' => 'PUT', 'headers' => ['Content-Type' => 'application/json'], 'expires_at' => '2026-10-05T10:15:00.000Z'],
            ], 201);
        }

        if ($request->method() === 'PUT' && str_starts_with($url, 'https://s3.test/upload/')) {
            return Http::response('', 200);
        }

        if ($request->method() === 'GET' && str_starts_with($url, 'https://scanner.test/scans/')) {
            $id = basename($url);

            return Http::response([
                'scan_id' => $id,
                'type' => 'locks',
                'status' => $status,
                'created_at' => '2026-10-05T10:00:00.000Z',
                'result_url' => $status === 'done' ? "https://s3.test/result/{$id}" : null,
                'error' => $error,
            ], 200);
        }

        if ($request->method() === 'GET' && str_starts_with($url, 'https://s3.test/result/')) {
            return Http::response($result, 200);
        }

        return Http::response('unexpected request ' . $request->method() . ' ' . $url, 500);
    });
}
