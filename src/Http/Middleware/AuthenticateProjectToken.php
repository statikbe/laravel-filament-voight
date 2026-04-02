<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Statikbe\FilamentVoight\Models\Project;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProjectToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable instanceof Project) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('voight_project', $accessToken->tokenable);

        return $next($request);
    }
}
