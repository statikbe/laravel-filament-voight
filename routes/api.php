<?php

use Illuminate\Support\Facades\Route;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Http\Controllers\LockFileController;
use Statikbe\FilamentVoight\Http\Controllers\SystemDetailsController;

Route::prefix('api/voight')->middleware(FilamentVoight::config()->getApiMiddleware())->group(function () {
    Route::post('lock-file', [LockFileController::class, 'store']);
    Route::post('system-details', [SystemDetailsController::class, 'store']);
});
