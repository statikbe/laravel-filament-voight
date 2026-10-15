<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum. Sanctum resolves a project's CI token to the Project as
 * "user", so without this check a sync token would open the MCP server.
 */
class EnsureMcpUserToken
{
    public const string ABILITY = 'voight:mcp';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $userModel = FilamentVoight::config()->getUserModel();

        if (! $user instanceof $userModel || ! method_exists($user, 'tokenCan') || ! $user->tokenCan(self::ABILITY)) {
            return response()->json(['message' => 'This token cannot be used for the Voight MCP server.'], 403);
        }

        return $next($request);
    }
}
