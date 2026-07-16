<?php
return [
    'grading' => [
        'auto_approve_confidence' => env('AUTO_APPROVE_CONFIDENCE', 92),
    ],
    'proctoring' => [
        'auto_terminate_risk_score' => 95,
        'screenshot_interval_seconds' => 60,
    ],
    'session' => [
        'auto_save_interval_seconds' => 15,
        'cache_ttl_hours' => 6,
    ],
];
