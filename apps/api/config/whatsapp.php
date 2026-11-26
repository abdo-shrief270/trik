<?php

declare(strict_types=1);

use Modules\WhatsApp\Infrastructure\Providers\Dialog360Provider;
use Modules\WhatsApp\Infrastructure\Providers\FakeProvider;
use Modules\WhatsApp\Infrastructure\Providers\MetaCloudProvider;
use Modules\WhatsApp\Infrastructure\Webhooks\Dialog360Webhook;
use Modules\WhatsApp\Infrastructure\Webhooks\FakeWebhook;
use Modules\WhatsApp\Infrastructure\Webhooks\MetaCloudWebhook;

return [
    // Country code used for local numbers that start with 0 (Egypt).
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '20'),

    // Public base URL that providers call back (defaults to APP_URL). Use a tunnel URL locally.
    'webhook_base_url' => env('WHATSAPP_WEBHOOK_BASE_URL'),

    'queue' => env('WHATSAPP_QUEUE', 'default'),

    // Meta's default per-number throughput is 80 messages/s (up to 1,000 after an upgrade).
    'default_messages_per_second' => (int) env('WHATSAPP_DEFAULT_MPS', 80),

    'retention' => [
        'webhook_events_months' => (int) env('WHATSAPP_WEBHOOK_RETENTION_MONTHS', 2),
        'inbound_keys_days' => (int) env('WHATSAPP_INBOUND_KEYS_DAYS', 30),
    ],

    // One adapter per provider. The fake one is refused in production.
    'adapters' => [
        '360dialog' => ['provider' => Dialog360Provider::class, 'webhook' => Dialog360Webhook::class],
        'meta_cloud' => ['provider' => MetaCloudProvider::class, 'webhook' => MetaCloudWebhook::class],
        'fake' => ['provider' => FakeProvider::class, 'webhook' => FakeWebhook::class],
    ],

    'providers' => [
        '360dialog' => [
            'base_url' => env('D360_BASE_URL', 'https://waba-v2.360dialog.io'),
        ],
        'meta_cloud' => [
            'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
            'graph_version' => env('META_GRAPH_VERSION', 'v24.0'),
            'app_secret' => env('META_APP_SECRET'),
            'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        ],
    ],
];
