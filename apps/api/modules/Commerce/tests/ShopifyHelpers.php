<?php

declare(strict_types=1);

/* Helpers shared by the Shopify feature tests (required, not a test file). */

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\Shopify\ShopifySignature;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;

const SHOP = 'laila-fashion.myshopify.com';

function gqlResponse(array $data): PromiseInterface
{
    return Http::response(['data' => $data, 'extensions' => ['cost' => ['requestedQueryCost' => 10, 'actualQueryCost' => 5,
        'throttleStatus' => ['maximumAvailable' => 2000.0, 'currentlyAvailable' => 1995, 'restoreRate' => 100.0]]]]);
}

/** A webhook as Shopify sends it: the exact raw body, signed with the app secret. */
function shopifyHook(string $topic, array $payload, string $shop = SHOP, ?string $id = null, string $secret = 'shh', string $path = '/webhooks/shopify'): TestResponse
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    app('auth')->forgetGuards();

    return test()->flushHeaders()->call('POST', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SHOPIFY_TOPIC' => $topic, 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $id ?? (string) Str::uuid(),
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
    ], $body);
}

/** Shopify's redirect to the OAuth callback (or app URL), signed. */
function signedQuery(array $query, string $secret = 'shh'): string
{
    $query += ['timestamp' => (string) time()];
    $query['hmac'] = hash_hmac('sha256', ShopifySignature::queryMessage($query), $secret);

    return http_build_query($query);
}

function connectedStore(string $ws, string $shop = SHOP, string $scopes = 'read_orders,write_orders'): Store
{
    return inWs($ws, fn (): Store => Store::query()->create([
        'platform' => Platform::Shopify, 'shop_domain' => $shop, 'access_token' => 'shpat_live', 'scopes' => $scopes,
        'status' => StoreStatus::Active, 'installed_at' => now(),
    ]));
}

function webhookOrder(array $overrides = []): array
{
    $p = json_decode((string) file_get_contents(__DIR__.'/Fixtures/shopify_order.json'), true);

    return array_replace($p, ['created_at' => now()->subMinutes(5)->toIso8601String(), 'updated_at' => now()->subMinutes(5)->toIso8601String()], $overrides);
}

/** Starts an install as $headers and returns the state Trik sent to Shopify. */
function startInstall(array $headers, string $shop = 'laila-fashion'): string
{
    $url = inboxAs($headers)->postJson('/v1/commerce/shopify/install', ['shop' => $shop])->assertOk()->json('data.authorize_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return $q['state'];
}
