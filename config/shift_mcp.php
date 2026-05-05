<?php

return [
    'web_enabled' => (bool) env('SHIFT_MCP_WEB_ENABLED', false),
    'auth_token' => env('SHIFT_MCP_AUTH_TOKEN'),
    'user_email' => env('SHIFT_MCP_USER_EMAIL'),
    'project_token' => env('SHIFT_MCP_PROJECT_TOKEN'),
];
