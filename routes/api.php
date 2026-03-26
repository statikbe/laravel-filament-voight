<?php

use Illuminate\Support\Facades\Route;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Http\Controllers\LockFileController;

Route::prefix('api/voight')->middleware(FilamentVoight::config()->getApiMiddleware())->group(function () {
    Route::post('lock-file', [LockFileController::class, 'store']);
});
