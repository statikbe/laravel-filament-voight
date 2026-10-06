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

        $environment->systemDetails()->updateOrCreate(
            ['environment_id' => $environment->getKey()],
            [
                'php_version' => $data['versions']['php'] ?? null,
                'laravel_version' => $data['versions']['laravel'] ?? null,
                'filament_version' => $data['versions']['filament'] ?? null,
                'livewire_version' => $data['versions']['livewire'] ?? null,
                'payload' => $data,
                'collected_at' => $data['collected_at'],
                'received_at' => now(),
            ],
        );

        return response()->noContent();
    }
}
