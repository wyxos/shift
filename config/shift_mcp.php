<?php

return [
    'http_enabled' => (bool) env('MCP_HTTP_ENABLED', false),

    'issuer' => rtrim((string) env('APP_URL'), '/'),

    'resource' => rtrim((string) env('APP_URL'), '/').'/mcp/shift',

    'scopes' => [
        'mcp:read' => 'Read MCP-enabled SHIFT projects, tasks, threads, and notifications.',
        'mcp:write' => 'Create and update supported SHIFT tasks and thread comments.',
    ],
];
