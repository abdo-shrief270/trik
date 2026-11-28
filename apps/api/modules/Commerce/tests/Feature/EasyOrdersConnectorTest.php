<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Commerce\Application\StoreCallFailed;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\EasyOrders\EasyOrdersClient;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Models\StoreWebhookEvent;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

const EO_STORE = '29bafd4f-5e5a-4faf-8f0f-6c4379eb65ef';
const EO_ORDER = '2692e31f-27f6-472d-b4cd-c0c1c168511c';

function eoOrder(array $overrides = []): array
{
    $o = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/easyorders_order.json'), true);

    return array_replace($o, ['created_at' => now()->subMinutes(3)->toIso8601String(), 'updated_at' => now()->subMinutes(3)->toIso8601String()], $overrides);
}

/** Starts an install and returns [callback path, webhook path] from the link Trik built. */
function eoStartInstall(array $headers): array
{
    $url = inboxAs($headers)->postJson('/v1/commerce/easyorders/install')->assertOk()->json('data.install_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY) ?: substr((string) strstr($url, '?'), 1), $q);

    return [parse_url($q['callback_url'], PHP_URL_PATH), parse_url($q['orders_webhook'], PHP_URL_PATH), $q, $url];
}

/** A connected EasyOrders store and its webhook path. */
function eoConnected(array $owner): array
{
    [$callback, $webhook] = eoStartInstall($owner['headers']);
    test()->flushHeaders()->postJson($callback, ['api_key' => 'eo-key-123', 'store_id' => EO_STORE])->assertOk();

    return [inWs($owner['workspace'], fn () => Store::query()->sole()), $webhook];
}

beforeEach(function (): void {
    config([
        'trik.features.commerce' => 'all', 'app.url' => 'https://api.trik.test', 'app.frontend_url' => 'https://app.trik.test',
        'commerce.easyorders.base_url' => 'https://api.easy-orders.net/api/v1/external-apps',
    ]);
    Queue::fake([SendOutgoingMessage::class]);
    $this->owner = ownerWithWorkspace('Laila Fashion');
    $this->ws = $this->owner['workspace'];
    $this->calls = [];
    $this->apiOrder = eoOrder();
    $this->respond = null;

    $test = $this;
    Http::fake(function (Request $r) use ($test) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $test->calls[] = [$r->method(), $path, $r->data(), $r->header('Api-Key')[0] ?? null];
        if ($test->respond instanceof Closure) {
            return ($test->respond)($r);
        }
        if ($r->method() === 'GET' && str_contains($path, '/external-apps/orders/')) {
            return $test->apiOrder === null ? Http::response(['message' => 'not found'], 404) : Http::response($test->apiOrder);
        }

        return Http::response(['ok' => true]);
    });
});

describe('connecting', function (): void {
    it("builds EasyOrders' install link with our callback and webhooks, for managers only", function (): void {
        [$callback, $webhook, $q, $url] = eoStartInstall($this->owner['headers']);

        expect(str_starts_with($url, 'https://app.easy-orders.net/#/install-app?'))->toBeTrue()
            ->and($q['app_name'])->toBe('Trik')
            ->and($q['permissions'])->toBe('orders:read,orders:update')
            ->and($callback)->toMatch('#^/webhooks/easyorders/install/[A-Za-z0-9]{48}$#')
            ->and($webhook)->toMatch('#^/webhooks/easyorders/[A-Za-z0-9]{48}$#')
            ->and($q['order_status_webhook'])->toBe($q['orders_webhook'])
            ->and($q['redirect_url'])->toBe('https://app.trik.test/settings/stores?easyorders=connected');

        $agent = teammate($this->ws, WorkspaceRole::Agent);
        inboxAs($agent['headers'])->postJson('/v1/commerce/easyorders/install')->assertForbidden();
    });

    it('connects on the callback: key encrypted, webhook token hashed, single use', function (): void {
        [$callback, $webhook] = eoStartInstall($this->owner['headers']);
        $this->flushHeaders()->postJson($callback, ['api_key' => 'eo-key-123', 'store_id' => strtoupper(EO_STORE)])->assertOk();

        $store = inWs($this->ws, fn () => Store::query()->sole());
        $raw = inWs($this->ws, fn () => DB::table('commerce_stores')->first(['access_token', 'webhook_token_hash']));
        expect($store->platform->value)->toBe('easyorders')
            ->and($store->shop_domain)->toBe(EO_STORE)
            ->and($store->access_token)->toBe('eo-key-123')
            ->and($raw->access_token)->not->toContain('eo-key-123')
            ->and($raw->webhook_token_hash)->toBe(hash('sha256', basename($webhook)))
            ->and($store->backfill)->toBe(['status' => 'unsupported']);

        $this->flushHeaders()->postJson($callback, ['api_key' => 'attacker', 'store_id' => (string) Str::uuid()])->assertNotFound();
        inboxAs($this->owner['headers'])->getJson('/v1/commerce/stores')->assertOk()
            ->assertJsonPath('data.0.settings', ['write_status' => true, 'write_address' => true])->assertJsonMissingPath('data.0.access_token');
        inboxAs($this->owner['headers'])->postJson("/v1/commerce/stores/{$store->id}/backfill", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'backfill-unsupported');
        expect($this->calls)->toBe([]);
    });

    it('refuses forged, malformed and stale callbacks', function (): void {
        $this->flushHeaders()->postJson('/webhooks/easyorders/install/'.Str::random(48), ['api_key' => 'k', 'store_id' => EO_STORE])->assertNotFound();
        $this->flushHeaders()->postJson('/webhooks/easyorders/install/short', ['api_key' => 'k', 'store_id' => EO_STORE])->assertNotFound();

        [$callback] = eoStartInstall($this->owner['headers']);
        $this->flushHeaders()->postJson($callback, ['api_key' => '', 'store_id' => 'not-a-uuid'])->assertUnprocessable()->assertJsonPath('code', 'invalid-callback');

        $admin = teammate($this->ws, WorkspaceRole::Admin);
        [$callback] = eoStartInstall($admin['headers']);
        WorkspaceMember::query()->where('user_id', $admin['user']->id)->update(['role' => WorkspaceRole::Agent]);
        $this->flushHeaders()->postJson($callback, ['api_key' => 'k', 'store_id' => EO_STORE])->assertForbidden();

        eoConnected($this->owner);
        $other = ownerWithWorkspace('Other');
        [$callback] = eoStartInstall($other['headers']);
        $this->flushHeaders()->postJson($callback, ['api_key' => 'k2', 'store_id' => EO_STORE])->assertConflict()->assertJsonPath('code', 'store-connected-elsewhere');
    });
});

describe('orders', function (): void {
    beforeEach(function (): void {
        [$this->store, $this->webhook] = eoConnected($this->owner);
    });

    it('imports what EasyOrders says, never what the webhook body claims', function (): void {
        // a forged body: a different price and phone; the order is read back with the store's key
        $forged = eoOrder(['total_cost' => 1, 'phone' => '01099999999']);
        $this->flushHeaders()->postJson($this->webhook, $forged)->assertOk();
        $this->flushHeaders()->postJson($this->webhook, $forged)->assertOk(); // retried: stored once

        inWs($this->ws, function (): void {
            $order = Order::query()->sole();
            expect($order->source->value)->toBe('easyorders')
                ->and($order->number)->toBe('#46')
                ->and($order->total)->toBe(110000)
                ->and($order->discount)->toBe(10000)
                ->and($order->customer_phone)->toBe('201012345678')
                ->and($order->deliveryAddress()->governorate?->value)->toBe('GZ')
                ->and($order->items()->orderBy('position')->pluck('variant')->all())->toBe(['color: أزرق · size: M', null])
                ->and(StoreWebhookEvent::query()->count())->toBe(1);
        });
        expect($this->calls[0])->toBe(['GET', '/api/v1/external-apps/orders/'.EO_ORDER, [], 'eo-key-123']);
    });

    it("follows status changes, and ignores orders that aren't this store's", function (): void {
        $this->flushHeaders()->postJson($this->webhook, eoOrder())->assertOk();
        $this->apiOrder = eoOrder(['status' => 'in_delivery', 'updated_at' => now()->toIso8601String()]);
        $this->flushHeaders()->postJson($this->webhook, ['event_type' => 'order-status-update', 'order_id' => EO_ORDER, 'old_status' => 'pending', 'new_status' => 'in_delivery'])->assertOk();
        expect(inWs($this->ws, fn () => Order::query()->sole()->status->value))->toBe('shipped');

        $this->apiOrder = eoOrder(['id' => (string) Str::uuid(), 'store_id' => (string) Str::uuid()]);
        $this->flushHeaders()->postJson($this->webhook, ['event_type' => 'order-status-update', 'order_id' => $this->apiOrder['id'], 'new_status' => 'confirmed'])->assertOk();
        expect(inWs($this->ws, fn () => Order::query()->count()))->toBe(1);
    });

    it('refuses unknown webhook URLs and, for hand-made webhooks, a wrong secret', function (): void {
        $this->flushHeaders()->postJson('/webhooks/easyorders/'.Str::random(48), eoOrder())->assertNotFound();

        inWs($this->ws, fn () => Store::query()->sole()->update(['webhook_secret' => 'GENERATED_SECRET']));
        $this->flushHeaders()->postJson($this->webhook, eoOrder())->assertUnauthorized();
        $this->flushHeaders()->withHeader('secret', 'GENERATED_SECRET')->postJson($this->webhook, eoOrder())->assertOk();

        // disconnected: the URL stops working
        inboxAs($this->owner['headers'])->deleteJson("/v1/commerce/stores/{$this->store->id}")->assertNoContent();
        $this->flushHeaders()->withHeader('secret', 'GENERATED_SECRET')->postJson($this->webhook, eoOrder(['short_id' => 47]))->assertNotFound();
    });

    it('writes confirmations, cancellations and fixed addresses back, without echo loops', function (): void {
        $this->flushHeaders()->postJson($this->webhook, eoOrder())->assertOk();
        $order = inWs($this->ws, fn () => Order::query()->sole());
        $this->calls = [];

        inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$order->id}", ['address' => ['governorate' => 'GZ', 'area' => 'الدقي', 'street' => 'التحرير', 'building' => '12']])->assertOk();
        inboxAs($this->owner['headers'])->postJson("/v1/orders/{$order->id}/confirm")->assertOk();

        expect($this->calls[0][0].' '.$this->calls[0][1])->toBe('POST /api/v1/external-apps/order-notes')
            ->and($this->calls[0][2])->toMatchArray(['order_id' => EO_ORDER, 'store_id' => EO_STORE, 'type' => 'private'])
            ->and($this->calls[0][2]['note'])->toContain('التحرير')
            ->and($this->calls[1])->toBe(['PATCH', '/api/v1/external-apps/orders/'.EO_ORDER.'/status', ['status' => 'confirmed'], 'eo-key-123'])
            ->and(count($this->calls))->toBe(2);

        // EasyOrders echoes the status change: read back, never written again
        $this->calls = [];
        $this->apiOrder = eoOrder(['status' => 'confirmed', 'updated_at' => now()->addSecond()->toIso8601String()]);
        $this->flushHeaders()->postJson($this->webhook, ['event_type' => 'order-status-update', 'order_id' => EO_ORDER, 'old_status' => 'pending', 'new_status' => 'confirmed'])->assertOk();
        expect(array_column($this->calls, 0))->toBe(['GET']);

        // switched off: nothing is sent
        inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['write_status' => false]])->assertOk();
        inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['cancel_in_store' => true]])->assertUnprocessable();
        $this->calls = [];
        inboxAs($this->owner['headers'])->postJson("/v1/orders/{$order->id}/cancel", [])->assertOk();
        expect($this->calls)->toBe([]);
    });

    it('marks the store uninstalled when the key stops working', function (): void {
        $this->respond = fn () => Http::response(['message' => 'Unauthorized'], 401);
        $this->flushHeaders()->postJson($this->webhook, eoOrder())->assertOk();

        expect(inWs($this->ws, fn () => Store::query()->sole())->only(['status', 'access_token']))->toBe(['status' => StoreStatus::Uninstalled, 'access_token' => null]);
    });

    it('stays under EasyOrders\' 40 calls a minute', function (): void {
        config(['commerce.easyorders.calls_per_minute' => 2]);
        $client = app(EasyOrdersClient::class);
        $store = inWs($this->ws, fn () => Store::query()->sole());
        $client->order($store, EO_ORDER);
        $client->order($store, EO_ORDER);

        try {
            $client->order($store, EO_ORDER);
            $this->fail('expected a retry');
        } catch (StoreCallFailed $e) {
            expect($e->retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
        }
        expect(count($this->calls))->toBe(2);
    });
});
