<?php

declare(strict_types=1);

namespace Modules\Commerce\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Commerce\Application\AcceptStoreWebhook;
use Modules\Commerce\Application\EasyOrders\EasyOrdersInstall;
use Modules\Commerce\Application\Shopify\InstallFailed;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\StoreRouter;

/**
 * What EasyOrders calls (no session, no Trik auth; the secret tokens in the URLs are the authentication):
 *  - POST /webhooks/easyorders/install/{token}  the end of the authorized-app install: {api_key, store_id}
 *  - POST /webhooks/easyorders/{hook}           order created / order status changed
 */
final class EasyOrdersController
{
    public const FAILURES_PER_MINUTE = 60;

    public function install(Request $request, string $token, EasyOrdersInstall $install): JsonResponse
    {
        $this->refuseIfFlooding($request);

        try {
            $store = $install->finish($token, $request->json()->all());
        } catch (InstallFailed $e) {
            RateLimiter::hit($this->failureKey($request), 60);

            throw match ($e->reason) {
                'expired' => Problem::notFound('install-not-found', 'Unknown or expired install link.'),
                'forbidden' => Problem::forbidden('forbidden', 'The member who started this install can no longer connect stores.'),
                'store-connected-elsewhere' => Problem::conflict('store-connected-elsewhere', 'This store is connected to another Trik workspace.'),
                default => Problem::unprocessable('invalid-callback', 'Expected {api_key, store_id}.'),
            };
        }

        return response()->json(['ok' => true, 'store' => $store->id]);
    }

    public function webhook(Request $request, string $hook, StoreRouter $router, TenantContext $context, AcceptStoreWebhook $accept): JsonResponse
    {
        $this->refuseIfFlooding($request);

        $route = $router->byWebhookToken($hook);
        if ($route === null) {
            RateLimiter::hit($this->failureKey($request), 60);

            throw Problem::notFound('unknown-webhook', 'Unknown webhook.');
        }

        $body = $request->getContent();
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            throw Problem::badRequest('invalid-body', 'The body is not a JSON object.');
        }

        return $context->run($route['workspace_id'], function () use ($request, $route, $payload, $body, $accept): JsonResponse {
            $store = Store::query()->find($route['store_id']);
            // a webhook set up by hand carries EasyOrders' `secret` header: check it when we know it
            if ($store?->webhook_secret !== null && ! hash_equals((string) $store->webhook_secret, (string) $request->header('secret', ''))) {
                RateLimiter::hit($this->failureKey($request), 60);

                throw Problem::make(401, 'invalid-signature', 'Invalid secret.');
            }

            $topic = ($payload['event_type'] ?? null) === 'order-status-update' ? 'easyorders/order-status' : 'easyorders/order-created';
            // EasyOrders sends no delivery id: an identical body is the same delivery
            $accept->handle($route['store_id'], $topic, hash('sha256', $body), $payload);

            return response()->json(['ok' => true]);
        });
    }

    private function refuseIfFlooding(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->failureKey($request), self::FAILURES_PER_MINUTE)) {
            throw new Problem(429, 'rate-limited', 'Too Many Requests', 'Too many invalid webhook deliveries.', [], [
                'Retry-After' => (string) RateLimiter::availableIn($this->failureKey($request)),
            ]);
        }
    }

    private function failureKey(Request $request): string
    {
        return 'webhook-failures:easyorders:ip:'.$request->ip();
    }
}
