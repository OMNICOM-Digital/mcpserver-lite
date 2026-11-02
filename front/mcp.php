<?php

/**
 * MCP endpoint: POST https://<glpi-url>/plugins/mcpserverlite/front/mcp.php
 *
 * Stateless JSON-RPC 2.0 over HTTP: one request in, one response out
 * ("Streamable HTTP" MCP transport, no SSE streaming in this skeleton).
 *
 * Deliberately does not use GLPI's normal Html::header() page bootstrap —
 * this behaves like a clean API endpoint, not an HTML page. Auth is done
 * entirely via OAuthTokenAuth against the Authorization header (OAuth is
 * the only supported auth path).
 *
 * Read-only by construction: ToolRegistry contains no write tool, and the
 * plugin ships no code that creates, updates or deletes GLPI data.
 */

use GlpiPlugin\Mcpserverlite\OAuthTokenAuth;
use GlpiPlugin\Mcpserverlite\ToolRegistry;

include(__DIR__ . '/../../../inc/includes.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // We don't support the optional GET-based SSE stream some Streamable
    // HTTP clients probe for — 405 is the spec-correct way to say "POST
    // only", distinct from a malformed-JSON-RPC-body error.
    header('Allow: POST');
    http_response_code(405);
    exit;
}

function mcp_error_response($id, int $code, string $message): void
{
    if ($code === 401) {
        global $CFG_GLPI;
        header('WWW-Authenticate: Bearer resource_metadata="' . $CFG_GLPI['url_base'] . '/plugins/mcpserverlite/front/oauth-protected-resource.php"');
    }
    http_response_code($code >= 400 && $code < 600 ? $code : 500);
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => ['code' => $code, 'message' => $message],
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);

if (!is_array($req) || !isset($req['method'])) {
    mcp_error_response($req['id'] ?? null, 400, 'invalid_json_rpc_request');
}

$id     = $req['id'] ?? null;
$method = $req['method'];
$params = $req['params'] ?? [];

// JSON-RPC notifications (no response expected/allowed) — most importantly
// "notifications/initialized", which MCP clients send right after a
// successful initialize handshake. Answering these with a JSON-RPC error
// (our old default: branch did) can make a client treat the connection as
// broken and never proceed to call tools/list.
if (str_starts_with($method, 'notifications/')) {
    http_response_code(202);
    exit;
}

// OAuth is the only auth path — the incoming bearer must be a real GLPI
// OAuth access token, resolved to whichever GLPI user actually
// authenticated (works for any number of people, no admin pre-mapping).
try {
    OAuthTokenAuth::authenticate();
} catch (Exception $oauthException) {
    mcp_error_response($id, 401, 'authentication_failed');
}

try {
    switch ($method) {
        case 'initialize':
            $result = [
                'protocolVersion' => $params['protocolVersion'] ?? '2025-06-18',
                'serverInfo'      => ['name' => 'glpi-mcp-lite', 'version' => PLUGIN_MCPSERVERLITE_VERSION],
                'capabilities'    => ['tools' => new stdClass()],
            ];
            break;

        case 'tools/list':
            $result = ['tools' => ToolRegistry::toMcpList()];
            break;

        case 'tools/call':
            $toolName = $params['name'] ?? '';
            $toolArgs = $params['arguments'] ?? [];
            $tool     = ToolRegistry::get($toolName);

            if ($tool === null) {
                mcp_error_response($id, 404, "unknown_tool: $toolName");
            }

            // Interface check: Standard (central) vs Self-Service (helpdesk)
            // tool visibility. Re-checked here, not just at tools/list time,
            // since nothing stops a client from calling a tool name it never
            // fetched from tools/list.
            if (!ToolRegistry::isAllowedForInterface($toolName)) {
                mcp_error_response($id, 403, "tool_not_available_for_interface: $toolName");
            }

            if (!is_array($toolArgs)) {
                $toolArgs = [];
            }

            $callResult = call_user_func($tool['handler'], $toolArgs);

            $result = [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($callResult)],
                ],
                'isError' => false,
            ];
            break;

        default:
            mcp_error_response($id, 400, "unsupported_method: $method");
    }
} catch (Exception $e) {
    // Tool-level failure: surface it as an MCP tool error, not a transport
    // error, so the AI model sees the message and can react/retry.
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => [
            'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            'isError' => true,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    // PHP \Error subclasses (TypeError, ValueError, ...) are not
    // \Exceptions, so a handler hitting one used to escape this file as an
    // uncaught fatal — the client saw a raw HTTP 500 / "server isn't
    // responding" instead of an MCP tool error. Found with glpi_submit_form
    // and an empty-string answer to an Item question (core's
    // QuestionTypeItem::formatRawAnswer() indexes the answer as an array).
    // The detail stays server-side (Rule 11: no internals to the caller); the
    // caller gets a clean tool error naming the tool. Details go to
    // files/_log/mcpserverlite.log, forced because it is always an unexpected fault.
    Toolbox::logInFile(
        'mcpserverlite',
        sprintf(
            "Unhandled %s in tool %s: %s (%s:%d)\n",
            get_class($e),
            $toolName ?? '(none)',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ),
        true
    );
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => [
            'content' => [[
                'type' => 'text',
                'text' => 'internal error while running ' . ($toolName ?? 'the tool')
                    . ' — nothing was reported as written; check the arguments and the plugin log',
            ]],
            'isError' => true,
        ],
    ]);
    exit;
}

echo json_encode([
    'jsonrpc' => '2.0',
    'id'      => $id,
    'result'  => $result,
]);
