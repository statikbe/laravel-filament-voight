<?php

namespace Statikbe\FilamentVoight\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Statikbe\FilamentVoight\Http\Requests\StoreSystemDetailsRequest;
use Statikbe\FilamentVoight\Services\LockFileSyncService;

class SystemDetailsController extends Controller
{
    public function store(StoreSystemDetailsRequest $request, LockFileSyncService $service): Response
    {
        $data = $request->validated();

        $environment = $service->resolveEnvironment(
            $request->attributes->get('voight_project'),
            $data['environment'],
        );

        $environment->update([
            'system_details' => $data,
            'system_details_received_at' => now(),
        ]);

        return response()->noContent();
    }
}
