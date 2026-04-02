<?php

namespace Statikbe\FilamentVoight\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Statikbe\FilamentVoight\Http\Requests\SyncLockFileRequest;
use Statikbe\FilamentVoight\Services\LockFileSyncService;

class LockFileController extends Controller
{
    public function store(SyncLockFileRequest $request, LockFileSyncService $service): JsonResponse
    {
        $sync = $service->sync(
            projectCode: $request->validated('project_code'),
            environmentName: $request->validated('environment'),
            lockfiles: $request->file('lockfiles'),
            project: $request->attributes->get('voight_project'),
        );

        return response()->json([
            'sync_id' => $sync->id,
            'status' => $sync->status->value,
        ], 202);
    }
}
