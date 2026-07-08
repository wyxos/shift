<?php

return [
    'notifications' => [
        'collaborator_grace_period_seconds' => (int) env('SHIFT_COLLABORATOR_NOTIFICATION_DELAY_SECONDS', 300),
    ],
];
