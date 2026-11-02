<?php

/**
 * Plugin: mcpserverlite
 * Read-only MCP (Model Context Protocol) endpoint built natively into GLPI,
 * so AI assistants can read GLPI data through internal PHP classes instead
 * of an external Node/Python MCP server proxying the public REST API.
 *
 * No write tools, no database tables, no GLPI right, no admin page: install,
 * activate, register an OAuth client, connect. Every call runs under the
 * real, OAuth-authenticated GLPI user, so normal profile/entity rights apply.
 *
 * Structure follows https://github.com/pluginsGLPI/example: namespaced
 * classes under src/ (PSR-4, GlpiPlugin\Mcpserverlite\...), autoloaded via
 * composer.json.
 */

use Glpi\Http\SessionManager;

define('PLUGIN_MCPSERVERLITE_VERSION', '1.1.0');

// GLPI 11.0.11 or newer — every verification pass was done against a live GLPI 11
// instance (11.0/bugfixes for anything version-sensitive). GLPI 10 has not
// been tested; widen this range only after an actual test pass.
define('PLUGIN_MCPSERVERLITE_MIN_GLPI', '11.0.11');
define('PLUGIN_MCPSERVERLITE_MAX_GLPI', '11.9.99');

/**
 * Init hook — REQUIRED.
 */
function plugin_init_mcpserverlite()
{
    // RFC 8414 requires the OAuth discovery document at the domain root:
    // /.well-known/oauth-authorization-server. A plugin cannot serve a file
    // from outside /plugins/mcpserverlite/, but GLPI's catch-all rewrite
    // forwards any request with no matching physical file to
    // public/index.php, which loads every active plugin's setup.php and
    // calls plugin_init_<key>() before GLPI's own routing gives up and 404s.
    // So the well-known path is recognized here and answered directly.
    //
    // Trade-off, deliberately accepted: this only works while the plugin is
    // active. If the full "mcpserver" plugin is active on the same GLPI, it
    // answers the same path with the same document — whichever loads first
    // responds; the output is identical, so there is no conflict.
    // Do not "clean this up" without re-reading this comment: it is a
    // pragmatic workaround, not GLPI's documented approach to RFC 8414.
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($requestPath === '/.well-known/oauth-authorization-server') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        header('Content-Type: application/json');
        echo json_encode([
            'issuer'                                => "https://{$host}",
            'authorization_endpoint'                 => "https://{$host}/api.php/v2/authorize",
            'token_endpoint'                          => "https://{$host}/api.php/v2/token",
            'response_types_supported'                => ['code'],
            'grant_types_supported'                    => ['authorization_code', 'refresh_token', 'client_credentials', 'password'],
            'code_challenge_methods_supported'         => ['S256'],
            'token_endpoint_auth_methods_supported'    => ['client_secret_post', 'none'],
            'scopes_supported'                         => ['api', 'user', 'email', 'status', 'inventory', 'graphql'],
        ]);
        exit;
    }
}

/**
 * REQUIRED: version info shown in Setup > Plugins.
 */
function plugin_version_mcpserverlite()
{
    return [
        'name'         => 'MCP Server Lite',
        'version'      => PLUGIN_MCPSERVERLITE_VERSION,
        'author'       => 'OMNICOM s.r.o.',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://omnicom.digital',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MCPSERVERLITE_MIN_GLPI,
                'max' => PLUGIN_MCPSERVERLITE_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_mcpserverlite_check_prerequisites()
{
    return true;
}

function plugin_mcpserverlite_check_config($verbose = false)
{
    return true;
}

/**
 * Runs early, before Symfony's Kernel dispatches to any controller —
 * required to exempt the endpoint from GLPI 11's token-less CSRF protection
 * (Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener), which
 * otherwise 403s any POST missing a same-origin Sec-Fetch-Site/Origin
 * header — exactly what an external client like Claude sends (no browser,
 * no Origin).
 *
 * "Stateless" is also semantically correct here: the endpoint does not rely
 * on a browser session/cookie, it authenticates each request itself via
 * OAuthTokenAuth.
 */
function plugin_mcpserverlite_boot()
{
    SessionManager::registerPluginStatelessPath('mcpserverlite', '#^/front/mcp\.php$#');
    SessionManager::registerPluginStatelessPath('mcpserverlite', '#^/front/oauth-protected-resource\.php$#');
}
