<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after the auth middleware. The remote MCP server is only open to users who
 * signed in through OAuth: the request must carry a Passport access token with the
 * mcp:use scope, owned by the configured user model. Anything else that reaches
 * this point is turned away, such as a project's sync token or a session login.
 */
class EnsureMcpUserToken
{
    /**
     * The scope laravel/mcp registers in Mcp::oauthRoutes().
     */
    public const string OAUTH_SCOPE = 'mcp:use';

    /**
     * Passport is optional, so its token classes are referenced by name.
     * Passport 13+ hands the user an AccessToken, older versions the Token model.
     */
    private const string PASSPORT_ACCESS_TOKEN = 'Laravel\Passport\AccessToken';

    private const string PASSPORT_TOKEN = 'Laravel\Passport\Token';

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
     * Requires a token that lists the scope explicitly: Passport's can() also
     * passes for "*" tokens, which were not issued for the MCP server.
     */
    private function hasMcpToken(object $user): bool
    {
        if (! method_exists($user, 'currentAccessToken')) {
            return false;
        }

        return in_array(self::OAUTH_SCOPE, $this->passportScopes($user->currentAccessToken()), true);
    }

    /**
     * @return array<mixed>
     */
    private function passportScopes(mixed $token): array
    {
        $scopes = match (true) {
            is_a($token, self::PASSPORT_ACCESS_TOKEN) => data_get($token, 'oauth_scopes'),
            is_a($token, self::PASSPORT_TOKEN) => data_get($token, 'scopes'),
            default => [],
        };

        return is_array($scopes) ? $scopes : [];
    }
}
