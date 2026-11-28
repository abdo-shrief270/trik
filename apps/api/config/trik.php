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

    // An organization holds at most this many workspaces (stops abuse; raise per customer).
    'max_workspaces_per_organization' => (int) env('MAX_WORKSPACES_PER_ORGANIZATION', 10),

    // Features still being rolled out: 'all', 'none', or a comma-separated list of workspace ids
    // (pilot sellers first). Checked by the "feature:<name>" middleware.
    // Confirmation never starts for an order placed longer ago than this (store backfills, late imports).
    'confirmation' => [
        'max_order_age_hours' => (int) env('CONFIRMATION_MAX_ORDER_AGE_HOURS', 48),
    ],

    'features' => [
        'inbox' => env('FEATURE_INBOX', 'all'),
        'orders' => env('FEATURE_ORDERS', 'all'),
        // Store connectors (Shopify): pilot sellers first.
        'commerce' => env('FEATURE_COMMERCE', 'none'),
        // Refusal risk score (#115).
        'risk' => env('FEATURE_RISK', 'none'),
    ],
];
