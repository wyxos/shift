<?php

return [
    'registration_mode' => env('REGISTRATION_MODE', 'open'),

    'source_maps' => [
        'path' => storage_path('app/private/shift-sdk-builds'),
    ],

    'notifications' => [
        'collaborator_grace_period_seconds' => (int) env('SHIFT_COLLABORATOR_NOTIFICATION_DELAY_SECONDS', 300),
        'callback_connect_timeout_seconds' => 5,
        'callback_timeout_seconds' => 15,
    ],
];
