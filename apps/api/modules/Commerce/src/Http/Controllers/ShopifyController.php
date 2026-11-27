<?php

declare(strict_types=1);

namespace Modules\Commerce\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Modules\Commerce\Application\AcceptStoreWebhook;
use Modules\Commerce\Application\Shopify\InstallFailed;
use Modules\Commerce\Application\Shopify\ShopifyInstall;
use Modules\Commerce\Application\Shopify\ShopifyWebhookHandler;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\Shopify\ShopifySignature;
use Modules\Commerce\Infrastructure\StoreRouter;

/**
 * Everything Shopify calls (no session, no Trik auth; Shopify's HMAC is the authentication):
 *  - GET  /webhooks/shopify/app             the app URL (install link, Shopify admin): back to the web app
 *  - GET  /webhooks/shopify/oauth/callback  the end of the OAuth grant
 *  - POST /webhooks/shopify                 every webhook topic (X-Shopify-Topic)
 */
final class ShopifyController
{
    /** Invalid deliveries from one IP per minute before that IP is refused for a minute. */
    public const FAILURES_PER_MINUTE = 60;

    public function app(Request $request, ShopifyInstall $install): RedirectResponse
    {
        try {
            $shop = $install->verifyAppLaunch($request->query());
        } catch (InstallFailed $e) {
            return $this->toWeb(['shopify_error' => $e->reason]);
        }

        // The web app signs the seller in, lets them pick the workspace, then calls POST /v1/commerce/shopify/install.
        return $this->toWeb(['connect' => 'shopify', 'shop' => $shop->value]);
    }

    public function callback(Request $request, ShopifyInstall $install): RedirectResponse
    {
        try {
            $store = $install->finish($request->query());
        } catch (InstallFailed $e) {
            return $this->toWeb(['shopify_error' => $e->reason]);
        }

        return $this->toWeb(['shopify' => 'connected', 'store' => $store->id]);
    }

    public function webhook(Request $request, StoreRouter $router, TenantContext $context, AcceptStoreWebhook $accept): JsonResponse
    {
        $this->refuseIfFlooding($request);
        $body = $request->getContent();

        if (! ShopifySignature::verifyWebhook($body, (string) $request->header('X-Shopify-Hmac-Sha256', ''), ShopifyInstall::secrets())) {
            RateLimiter::hit($this->failureKey($request), 60);

            throw Problem::make(401, 'invalid-signature', 'Invalid signature.');
        }

        $topic = strtolower((string) $request->header('X-Shopify-Topic', ''));
        if (! in_array($topic, ShopifyWebhookHandler::TOPICS, true)) {
            return response()->json(['ok' => true, 'ignored' => 'topic']); // signed, but not one Trik uses
        }

        try {
            $shop = ShopDomain::fromShopify((string) $request->header('X-Shopify-Shop-Domain', ''));
        } catch (InvalidArgumentException) {
            throw Problem::badRequest('invalid-shop', 'Missing or invalid shop domain.');
        }

        $route = $router->byShop(Platform::Shopify, $shop->value);
        if ($route === null) {
            // not connected (any more): answer 200 so Shopify doesn't keep retrying for 48 hours
            return response()->json(['ok' => true, 'ignored' => 'not-connected']);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            throw Problem::badRequest('invalid-body', 'The body is not a JSON object.');
        }

        $delivery = (string) ($request->header('X-Shopify-Webhook-Id') ?: $request->header('X-Shopify-Event-Id') ?: hash('sha256', $topic.$body));

        $context->run($route['workspace_id'], fn () => $accept->handle($route['store_id'], $topic, $delivery, $payload));

        return response()->json(['ok' => true]);
    }

    /** @param array<string, string> $query */
    private function toWeb(array $query): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/settings/stores?'.http_build_query($query));
    }

    /** Only invalid deliveries count; Shopify's valid traffic is never limited. */
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
        return 'webhook-failures:shopify:ip:'.$request->ip();
    }
}
