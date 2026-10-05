<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Support\CreatedScan;
use Statikbe\FilamentVoight\Support\ScanError;
use Statikbe\FilamentVoight\Support\ScanResponse;
use Statikbe\FilamentVoight\Support\ScanStatus;

class OsvScannerClient
{
    /**
     * @param  array<int, array{ecosystem: string, name: string, version: string}>  $packages
     */
    public function scanPackages(array $packages, string $batchId): ScanResponse
    {
        $url = FilamentVoight::config()->getScannerPackagesUrl();

        if (! $url) {
            throw new RuntimeException('OSV scanner packages URL is not configured. Set VOIGHT_SCANNER_PACKAGES_URL.');
        }

        $response = Http::timeout(120)
            ->connectTimeout(10)
            ->withToken(FilamentVoight::config()->getScannerToken() ?? '')
            ->post($url, ['batch_id' => $batchId, 'packages' => array_values($packages)]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "OSV scanner /packages returned HTTP {$response->status()}: " . mb_substr($response->body(), 0, 500)
            );
        }

        return ScanResponse::fromArray($response->json());
    }

    /**
     * @param  array<string, string>  $files  filename => contents
     */
    public function scanLockfiles(array $files, string $projectCode, string $environmentName): ScanResponse
    {
        $url = FilamentVoight::config()->getScannerUrl();

        if (! $url) {
            throw new RuntimeException('OSV scanner URL is not configured. Set VOIGHT_SCANNER_URL.');
        }

        $request = Http::timeout(120)
            ->connectTimeout(10)
            ->withToken(FilamentVoight::config()->getScannerToken() ?? '');

        foreach ($files as $filename => $contents) {
            $request = $request->attach($filename, $contents, $filename);
        }

        $response = $request->post($url, [
            'project_code' => $projectCode,
            'environment' => $environmentName,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "OSV scanner /locks returned HTTP {$response->status()}: " . mb_substr($response->body(), 0, 500)
            );
        }

        return ScanResponse::fromArray($response->json());
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function createScan(OsvScanType $type, array $meta, ?string $callbackUrl): CreatedScan
    {
        $response = $this->api()->post($this->scansUrl(), [
            'type' => $type->value,
            'callback_url' => $callbackUrl,
            // An object even when empty: the scanner rejects a JSON array.
            'meta' => (object) $meta,
        ]);

        $this->ensureSuccessful($response, 'POST /scans');

        return new CreatedScan(
            scanId: (string) $response->json('scan_id'),
            uploadUrl: (string) $response->json('upload.url'),
            uploadHeaders: (array) ($response->json('upload.headers') ?? []),
        );
    }

    public function upload(CreatedScan $scan, string $body): void
    {
        // Presigned S3 URL: the signature is the credential, so no bearer token.
        $response = Http::timeout(60)
            ->connectTimeout(10)
            ->withHeaders($scan->uploadHeaders)
            ->withBody($body, $scan->uploadHeaders['Content-Type'] ?? 'application/json')
            ->put($scan->uploadUrl);

        if (! $response->successful()) {
            throw new RuntimeException("Uploading OSV scan input returned HTTP {$response->status()}");
        }
    }

    public function getScan(string $scannerScanId): ScanStatus
    {
        $response = $this->api()->get($this->scansUrl() . '/' . $scannerScanId);

        if ($response->status() === 404) {
            return new ScanStatus(ScanStatus::FAILED, error: new ScanError('not_found', "The scanner has no scan {$scannerScanId}", false));
        }

        $this->ensureSuccessful($response, 'GET /scans/{id}');

        $status = (string) $response->json('status');

        if ($status === ScanStatus::FAILED) {
            return new ScanStatus($status, error: ScanError::fromArray((array) ($response->json('error') ?? [])));
        }

        if ($status !== ScanStatus::DONE) {
            return new ScanStatus($status);
        }

        // Presigned result URL: no bearer token.
        $result = Http::timeout(60)->connectTimeout(10)->get((string) $response->json('result_url'));

        if (! $result->successful()) {
            throw new RuntimeException("Downloading OSV scan result returned HTTP {$result->status()}");
        }

        return new ScanStatus($status, result: ScanResponse::fromArray((array) $result->json()));
    }

    private function api(): PendingRequest
    {
        return Http::timeout(15)
            ->connectTimeout(10)
            ->acceptJson()
            ->withToken(FilamentVoight::config()->getScannerToken() ?? '');
    }

    private function scansUrl(): string
    {
        $url = FilamentVoight::config()->getScannerUrl();

        if (! $url) {
            throw new RuntimeException('OSV scanner URL is not configured. Set VOIGHT_SCANNER_URL.');
        }

        return rtrim($url, '/');
    }

    private function ensureSuccessful(Response $response, string $call): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(
                "OSV scanner {$call} returned HTTP {$response->status()}: " . mb_substr($response->body(), 0, 500)
            );
        }
    }
}
