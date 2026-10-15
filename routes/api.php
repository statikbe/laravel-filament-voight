<?php

use Illuminate\Support\Facades\Route;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Http\Controllers\LockFileController;
use Statikbe\FilamentVoight\Http\Controllers\ScanCallbackController;
use Statikbe\FilamentVoight\Http\Controllers\SystemDetailsController;
use Statikbe\FilamentVoight\Http\Middleware\VerifyScannerCallbackSignature;

Route::prefix('api/voight')->middleware(FilamentVoight::config()->getApiMiddleware())->group(function () {
    Route::post('lock-file', [LockFileController::class, 'store']);
    Route::post('system-details', [SystemDetailsController::class, 'store']);
});

// Outside the project-token middleware: the scanner authenticates with an HMAC signature.
Route::post('api/voight/scans/callback', ScanCallbackController::class)
    ->middleware(VerifyScannerCallbackSignature::class)
    ->name('voight.scans.callback');
