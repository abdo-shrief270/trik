<?php

declare(strict_types=1);

return [
    // Cache store for Idempotency-Key responses (null = the default store; Redis in production).
    'idempotency_store' => env('IDEMPOTENCY_STORE'),

    // Largest accepted request body (also enforce at the edge). Media uploads will get their own
    // route with a higher limit (or go straight to object storage with a signed URL).
    'max_body_bytes' => [
        'api' => (int) env('MAX_BODY_BYTES_API', 2 * 1024 * 1024),
        'webhooks' => (int) env('MAX_BODY_BYTES_WEBHOOKS', 1024 * 1024),
    ],

    // A user can own at most this many workspaces (stops sign-up abuse; raise per customer).
    'max_owned_workspaces' => (int) env('MAX_OWNED_WORKSPACES', 10),
];
