<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure\EasyOrders;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Commerce\Application\StoreCallFailed;
use Modules\Commerce\Infrastructure\Models\Store;

/**
 * EasyOrders' public API (https://public-api-docs.easy-orders.net), with the store's key in `Api-Key`.
 * EasyOrders allows 40 calls a minute per key: every call first takes a slot from a per-store limiter
 * (35 by default); without one, the job is released until the next minute instead of being refused.
 */
final class EasyOrdersClient
{
    /** @return array<string, mixed>|null the order, or null when EasyOrders doesn't have it (any more) */
    public function order(Store $store, string $orderId): ?array
    {
        $response = $this->send($store, fn (PendingRequest $http) => $http->get('/orders/'.rawurlencode($orderId)), allow404: true);
        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();
        // the docs show the order itself; tolerate an envelope too
        $order = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

        return is_array($order) ? $order : null;
    }

    /** One of EasyOrders' statuses (pending, confirmed, canceled…). */
    public function updateStatus(Store $store, string $orderId, string $status): void
    {
        $this->send($store, fn (PendingRequest $http) => $http->patch('/orders/'.rawurlencode($orderId).'/status', ['status' => $status]));
    }

    /** A note on the order; private ones are only seen by the seller. */
    public function addNote(Store $store, string $orderId, string $note, string $type = 'private'): void
    {
        $this->send($store, fn (PendingRequest $http) => $http->post('/order-notes', [
            'order_id' => $orderId, 'note' => $note, 'store_id' => $store->shop_domain, 'type' => $type,
        ]));
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(Store $store, callable $call, bool $allow404 = false): Response
    {
        $key = 'easyorders-calls:'.$store->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('commerce.easyorders.calls_per_minute', 35))) {
            throw StoreCallFailed::throttled(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        try {
            $response = $call(Http::baseUrl(rtrim((string) config('commerce.easyorders.base_url'), '/'))
                ->withHeaders(['Api-Key' => (string) $store->access_token])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('commerce.easyorders.timeout', 15)));
        } catch (ConnectionException $e) {
            throw new StoreCallFailed('EasyOrders unreachable: '.$e->getMessage(), 30);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new StoreCallFailed('EasyOrders refused the key (HTTP '.$response->status().').', null, true);
        }
        if ($response->status() === 429) {
            throw StoreCallFailed::throttled(60); // "wait for the next minute"
        }
        if ($response->serverError()) {
            throw new StoreCallFailed('EasyOrders unavailable (HTTP '.$response->status().').', 30);
        }
        if ($response->failed() && ! ($allow404 && $response->status() === 404)) {
            throw new StoreCallFailed('EasyOrders request failed (HTTP '.$response->status().'): '.Str::limit($response->body(), 300));
        }

        return $response;
    }
}
