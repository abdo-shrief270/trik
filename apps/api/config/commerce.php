<?php

declare(strict_types=1);

return [

    // Queue for store webhooks, refreshes and backfills.
    'queue' => env('COMMERCE_QUEUE', 'default'),

    // Base URL Shopify calls (OAuth callback, app URL, webhooks). Defaults to APP_URL.
    'public_url' => env('COMMERCE_PUBLIC_URL'),

    // Processed webhook deliveries are kept this long (debugging, support), then deleted.
    'webhook_retention_days' => (int) env('COMMERCE_WEBHOOK_RETENTION_DAYS', 30),

    'shopify' => [
        // From the app in the Shopify Dev Dashboard (Settings → Client credentials).
        'client_id' => env('SHOPIFY_CLIENT_ID', ''),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),
        // While rotating the secret, the old one still verifies webhooks and OAuth redirects.
        'previous_client_secret' => env('SHOPIFY_PREVIOUS_CLIENT_SECRET', ''),

        // Least privilege for what Trik does: read orders and their fulfillments and customers;
        // write_orders is for the status / address write-back that comes next (asking for it now saves
        // every seller a second approval screen). read_all_orders (needs Shopify's approval) lets the
        // backfill reach 90 days; without it Shopify only serves the last 60.
        'scopes' => env('SHOPIFY_SCOPES', 'read_orders,write_orders,read_fulfillments,read_customers'),

        // Quarterly Admin API version; supported for 12 months after release.
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),

        'backfill_days' => (int) env('SHOPIFY_BACKFILL_DAYS', 90),

        // Install links and OAuth callbacks older than this are refused (replay).
        'oauth_max_age_seconds' => 600,

        // HTTP timeouts for Shopify calls, in seconds.
        'timeout' => (int) env('SHOPIFY_TIMEOUT', 15),
    ],
];
