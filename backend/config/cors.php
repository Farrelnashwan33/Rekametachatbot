<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
            env('FRONTEND_URL', 'http://localhost:5173'),
            'http://127.0.0.1:5173',
            'http://localhost:4173',
            'http://127.0.0.1:4173',
        ])))
    ))),

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?rekametachat\.pages\.dev$#',
    ],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'Origin', 'X-CSRF-TOKEN'],

    'exposed_headers' => ['Content-Disposition', 'X-Stream-Conversation-Id', 'X-Stream-Message-Id'],

    'max_age' => 3600,

    'supports_credentials' => true,

];
