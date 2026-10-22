<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:sanctum. Sanctum resolves a project's CI token to the Project as
 * "user", so without this check a sync token would open the MCP server. Only a
 * user token issued with the voight:mcp ability gets through.
 */
class EnsureMcpUserToken
{
    public const string ABILITY = 'voight:mcp';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $userModel = FilamentVoight::config()->getUserModel();

        if (! $user instanceof $userModel || ! $this->hasMcpToken($user)) {
            return response()->json(['message' => 'This token cannot be used for the Voight MCP server.'], 403);
        }

        return $next($request);
    }

    /**
     * Requires a stored token that lists the MCP ability explicitly. Sanctum's
     * tokenCan() also passes for "*" tokens and for session logins (a
     * TransientToken), neither of which was issued for the MCP server.
     */
    private function hasMcpToken(object $user): bool
    {
        if (! method_exists($user, 'currentAccessToken')) {
            return false;
        }

        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && in_array(self::ABILITY, $token->abilities ?? [], true);
    }
}
