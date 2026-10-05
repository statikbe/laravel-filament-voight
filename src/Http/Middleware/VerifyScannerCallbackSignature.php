<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the scanner's HMAC signature over "timestamp.raw_body" and rejects
 * timestamps older or newer than five minutes (replay window).
 */
class VerifyScannerCallbackSignature
{
    private const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = FilamentVoight::config()->getScannerCallbackSecret();
        $timestamp = (string) $request->header('X-Voight-Timestamp', '');
        $signature = (string) $request->header('X-Voight-Signature', '');

        if ($secret === null || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
