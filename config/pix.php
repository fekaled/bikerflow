<?php

return [
    'gateway' => [
        'driver' => env('PIX_GATEWAY_DRIVER', 'mock'),
        'timeout' => env('PIX_GATEWAY_TIMEOUT', 30),
        'mock' => [
            'holder_name_prefix' => 'MOCK HOLDER for',
        ],
    ],
    'webhook' => [
        // Phase 4C stub — webhook signature verification
        'secret' => env('PIX_WEBHOOK_SECRET', 'default-dev-secret-change-in-production'),
        'algorithm' => env('PIX_WEBHOOK_ALGORITHM', 'sha256'),
        'ip_whitelist' => env('PIX_WEBHOOK_IP_WHITELIST', ''),
    ],
];