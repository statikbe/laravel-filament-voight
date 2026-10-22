<?php

namespace Statikbe\FilamentVoight\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\CompleteOsvScanJob;
use Statikbe\FilamentVoight\Models\OsvScan;

class ScanCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $scannerScanId = $request->json('scan_id');

        if (! is_string($scannerScanId) || $scannerScanId === '') {
            return response()->json(['error' => 'Unknown scan'], 404);
        }

        /** @var class-string<OsvScan> $scanModel */
        $scanModel = FilamentVoight::config()->getOsvScanModel();
        $scan = $scanModel::query()->where('scanner_scan_id', $scannerScanId)->first();

        if (! $scan) {
            return response()->json(['error' => 'Unknown scan'], 404);
        }

        // Results are never read from the callback; the job re-reads the status from the scanner.
        CompleteOsvScanJob::dispatch($scan, $scannerScanId);

        return response()->json(['status' => 'queued'], 202);
    }
}
