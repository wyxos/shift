<?php

return [
    'rewrite' => [
        'enabled' => (bool) env('AI_REWRITE_ENABLED', false),
        'provider' => env('AI_REWRITE_PROVIDER', env('AI_DEFAULT_PROVIDER', 'openai')),
        'model' => env('AI_REWRITE_MODEL'),
        'timeout' => (int) env('AI_REWRITE_TIMEOUT', 60),
    ],

    'email_import' => [
        'enabled' => (bool) env('AI_EMAIL_IMPORT_ENABLED', false),
        'provider' => env('AI_EMAIL_IMPORT_PROVIDER', env('AI_DEFAULT_PROVIDER', 'openai')),
        'model' => env('AI_EMAIL_IMPORT_MODEL'),
        'timeout' => (int) env('AI_EMAIL_IMPORT_TIMEOUT', 60),
    ],
];
