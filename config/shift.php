<?php

return [
    'registration_policy' => env('REGISTRATION_POLICY', 'open'),

    'notifications' => [
        'collaborator_grace_period_seconds' => (int) env('SHIFT_COLLABORATOR_NOTIFICATION_DELAY_SECONDS', 300),
        'callback_connect_timeout_seconds' => 5,
        'callback_timeout_seconds' => 15,
    ],
];
