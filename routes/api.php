<?php

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Http\Controllers\CollectSystemDetailsController;
use Statikbe\FilamentVoight\Http\Controllers\LockFileController;
use Statikbe\FilamentVoight\Http\Controllers\SystemDetailsController;

Route::prefix('api/voight')->middleware(FilamentVoight::config()->getApiMiddleware())->group(function () {
    Route::post('lock-file', [LockFileController::class, 'store']);
    Route::post('system-details', [SystemDetailsController::class, 'store']);
});

// Outside the token group: authenticated by a one-minute relative signature, not the project token.
// Runs inside the client app and is called by `voight:push-system-details`.
Route::get('api/voight/system-details/collect', CollectSystemDetailsController::class)
    ->middleware([ValidateSignature::relative(), 'throttle:6,1'])
    ->name('voight.system-details.collect');
