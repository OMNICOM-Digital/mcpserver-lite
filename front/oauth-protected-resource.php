<?php

/**
 * RFC 9728 Protected Resource Metadata for the MCP endpoint.
 * URL is not spec-fixed (unlike the authorization-server metadata) —
 * clients reach it via the WWW-Authenticate "resource_metadata" hint on a
 * 401 from front/mcp.php, so it's free to live under our own plugin path.
 */

include(__DIR__ . '/../../../inc/includes.php');

global $CFG_GLPI;

header('Content-Type: application/json');
echo json_encode([
    'resource'              => $CFG_GLPI['url_base'] . '/plugins/mcpserverlite/front/mcp.php',
    'authorization_servers' => [$CFG_GLPI['url_base']],
]);
