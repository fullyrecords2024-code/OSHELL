<?php

return [
    'pin' => [
        'fingerprint_key' => env('POS_PIN_FINGERPRINT_KEY'),
        'key_version' => (int) env('POS_PIN_FINGERPRINT_KEY_VERSION', 1),
        'max_attempts' => (int) env('POS_PIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('POS_PIN_LOCK_MINUTES', 15),
        'max_lock_minutes' => (int) env('POS_PIN_MAX_LOCK_MINUTES', 60),
    ],

    'shift' => [
        'variance_approval_threshold' => (float) env('POS_SHIFT_VARIANCE_APPROVAL_THRESHOLD', 100),
    ],
];
