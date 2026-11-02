<?php

namespace GlpiPlugin\Mcpserverlite;

use Auth;
use Exception;
use Glpi\Http\Request as GlpiRequest;
use Glpi\OAuth\Server as OAuthServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Session;
use User;

/**
 * Per-user auth via GLPI's own built-in OAuth2 authorization server
 * (Glpi\OAuth\Server, league/oauth2-server under the hood) — the same
 * mechanism GLPI's own v2 API uses. Each AI-client user does a normal
 * OAuth authorization-code flow against GLPI's own
 * /api.php/v2/authorize + /api.php/v2/token endpoints and logs in as
 * THEMSELVES in their browser — no admin has to pre-issue or map a token
 * per person. This is what makes the connector "universal" instead of
 * tied to one shared service account. (Compare the removed ClientAuth —
 * static bearer-token auth for e.g. a background integration that isn't
 * "a person using Claude" — planned to come back in a later version.)
 *
 * Verified 2026-09 directly against glpi-project/glpi core:
 *   - src/Glpi/OAuth/Server.php            (grant types, validateAccessToken())
 *   - src/Glpi/Api/HL/Router.php            (startTemporarySession() — the
 *     exact validate -> Auth -> Session::init() sequence mirrored below)
 *   - src/Glpi/Controller/ApiController.php (how a Glpi\Http\Request is
 *     built from raw PHP superglobals: method + getallheaders() + raw body)
 *
 * Prerequisite: register an OAuth client in GLPI at Setup > General >
 * OAuth clients, with the redirect URI set to whatever callback URL the
 * AI client's OAuth flow uses, and configure that client's client_id
 * (+ secret, for a confidential client) on the Claude side.
 */
class OAuthTokenAuth
{
    /**
     * @throws Exception on any auth failure — front/mcp.php turns this
     *                    into a JSON-RPC error response.
     */
    public static function authenticate(): void
    {
        $request = new GlpiRequest(
            $_SERVER['REQUEST_METHOD'] ?? 'POST',
            $_SERVER['REQUEST_URI'] ?? '/',
            getallheaders() ?: [],
            file_get_contents('php://input') ?: null
        );

        try {
            $result = OAuthServer::validateAccessToken($request);
        } catch (OAuthServerException $e) {
            throw new Exception('oauth_token_rejected: ' . $e->getMessage(), 401);
        }

        if (empty($result['user_id'])) {
            throw new Exception('oauth_token_has_no_user', 401);
        }

        // Require the 'api' scope — the same default GLPI's own v2 API
        // expects for general endpoints (see OAuthRequestMiddleware).
        if (!in_array('api', $result['scopes'] ?? [], true)) {
            throw new Exception('oauth_token_missing_api_scope', 403);
        }

        $auth = new Auth();
        $auth->auth_succeded = true;
        $auth->user = new User();
        $auth->user->getFromDB($result['user_id']);
        Session::init($auth);
    }
}
