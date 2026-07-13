<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Support\ScanResponse;

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
}
