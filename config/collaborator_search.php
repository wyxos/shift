<?php

return [
    'enabled' => env('COLLABORATOR_SEARCH_ENABLED', env('APP_ENV') === 'local'),
    'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
    'key' => env('MEILISEARCH_KEY'),
    'index_prefix' => env('MEILISEARCH_COLLABORATORS_INDEX', 'shift_collaborators'),
];
