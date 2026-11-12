<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after the auth middleware. Sanctum resolves a project's CI token to the
 * Project as "user", so without this check a sync token would open the MCP
 * server. Only a user token issued for the MCP server gets through: a Sanctum
 * token with the voight:mcp ability, or a Passport (OAuth) token with the
 * mcp:use scope when the host enables OAuth.
 */
class EnsureMcpUserToken
{
    public const string ABILITY = 'voight:mcp';

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
     * Requires a stored token that lists the MCP ability or scope explicitly.
     * Sanctum's tokenCan() and Passport's can() also pass for "*" tokens, and
     * session logins get a TransientToken; none of these was issued for the
     * MCP server.
     */
    private function hasMcpToken(object $user): bool
    {
        if (! method_exists($user, 'currentAccessToken')) {
            return false;
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return in_array(self::ABILITY, $token->abilities ?? [], true);
        }

        return in_array(self::OAUTH_SCOPE, $this->passportScopes($token), true);
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
