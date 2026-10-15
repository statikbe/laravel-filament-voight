<?php

namespace Statikbe\FilamentVoight\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Statikbe\FilamentVoight\Services\SystemDetailsCollector;

class CollectSystemDetailsController extends Controller
{
    public function __invoke(SystemDetailsCollector $collector): JsonResponse
    {
        return response()->json($collector->collect());
    }
}
