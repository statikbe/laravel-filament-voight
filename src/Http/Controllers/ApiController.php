<?php

namespace Statikbe\FilamentVoight\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ApiController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Hello from laravel-filament-voight!',
        ]);
    }
}
