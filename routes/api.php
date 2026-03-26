<?php

use Illuminate\Support\Facades\Route;
use Statikbe\FilamentVoight\Http\Controllers\ApiController;

Route::prefix('api/voight')->group(function () {
    Route::get('/', [ApiController::class, 'index']);
});
