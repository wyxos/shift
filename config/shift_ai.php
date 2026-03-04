<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Local AI Provider
    |--------------------------------------------------------------------------
    |
    | SHIFT currently supports local model providers for rewrite workflows.
    | Set SHIFT_AI_PROVIDER to "ollama" or "lmstudio".
    |
    */
    'provider' => env('SHIFT_AI_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Shared Model Settings
    |--------------------------------------------------------------------------
    */
    'model' => env('SHIFT_AI_MODEL', 'llama3.1'),
    'timeout' => (int) env('SHIFT_AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Ollama Provider
    |--------------------------------------------------------------------------
    */
    'ollama' => [
        'base_url' => env('SHIFT_AI_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LM Studio (OpenAI-Compatible) Provider
    |--------------------------------------------------------------------------
    */
    'lmstudio' => [
        'base_url' => env('SHIFT_AI_LMSTUDIO_BASE_URL', 'http://127.0.0.1:1234/v1'),
        'api_key' => env('SHIFT_AI_LMSTUDIO_API_KEY'),
    ],
];
