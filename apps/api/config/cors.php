<?php

declare(strict_types=1);

/*
 * Only the Trik web app may call the API from a browser, with cookies (Sanctum SPA).
 * Mobile apps and servers don't use CORS.
 */
return [
    'paths' => ['v1/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Workspace-Id', 'Idempotency-Key', 'X-Request-Id', 'X-XSRF-TOKEN', 'X-Requested-With'],
    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replayed', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => true,
];
