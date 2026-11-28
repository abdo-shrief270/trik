<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Commerce\Application\Shopify\ShopifyCallFailed;
use Modules\Commerce\Application\Shopify\ShopifyQueries;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Models\StoreWebhookEvent;
use Modules\Commerce\Infrastructure\Shopify\CostThrottle;
use Modules\Commerce\Infrastructure\Shopify\ShopifyAdminClient;
use Modules\Confirmation\Infrastructure\Models\ConfirmationRun;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Provider;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

require_once dirname(__DIR__).'/ShopifyHelpers.php';

beforeEach(function (): void {
    config([
        'commerce.shopify.client_id' => 'cid', 'commerce.shopify.client_secret' => 'shh',
        'trik.features.commerce' => 'all', 'app.frontend_url' => 'https://app.trik.test', 'app.url' => 'https://api.trik.test',
    ]);
    Queue::fake([SendOutgoingMessage::class]);

    $this->owner = ownerWithWorkspace('Laila Fashion');
    $this->ws = $this->owner['workspace'];
    $this->jsonl = '';
    $this->refreshNode = null;
    $this->calls = [];

    $test = $this;
    Http::fake(function (Request $r) use ($test) {
        $test->calls[] = $r->url();
        if (str_ends_with($r->url(), '/admin/oauth/access_token')) {
            return Http::response(['access_token' => 'shpat_from_shopify', 'scope' => 'read_orders,write_orders,read_customers']);
        }
        if (str_contains($r->url(), '/admin/api/2026-10/graphql.json')) {
            $q = (string) $r['query'];

            return match (true) {
                str_contains($q, 'shop {') => gqlResponse(['shop' => ['name' => 'Laila Fashion', 'myshopifyDomain' => SHOP]]),
                str_contains($q, 'bulkOperationRunQuery') => gqlResponse(['bulkOperationRunQuery' => ['bulkOperation' => ['id' => 'gid://shopify/BulkOperation/77', 'status' => 'CREATED'], 'userErrors' => []]]),
                str_contains($q, 'on BulkOperation') => gqlResponse(['node' => ['id' => 'gid://shopify/BulkOperation/77', 'status' => 'COMPLETED', 'errorCode' => null, 'objectCount' => '6',
                    'url' => $test->jsonl === '' ? null : 'https://storage.googleapis.com/shopify-tiers-assets-prod-us-east1/bulk-77.jsonl']]),
                str_contains($q, 'order(id') => $test->refreshNode instanceof Closure ? ($test->refreshNode)() : gqlResponse(['order' => $test->refreshNode]),
                default => Http::response('unexpected query', 500),
            };
        }
        if (str_starts_with($r->url(), 'https://storage.googleapis.com/')) {
            return Http::response($test->jsonl);
        }

        return Http::response('not faked: '.$r->url(), 500);
    });
});

describe('connecting a store', function (): void {
    it('sends the owner to Shopify with the app, scopes, callback and a state', function (): void {
        $url = inboxAs($this->owner['headers'])->postJson('/v1/commerce/shopify/install', ['shop' => 'https://Laila-Fashion.myshopify.com/admin'])
            ->assertOk()->assertJsonPath('data.shop_domain', SHOP)->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        expect(str_starts_with($url, 'https://'.SHOP.'/admin/oauth/authorize?'))->toBeTrue()
            ->and($q['client_id'])->toBe('cid')
            ->and($q['scope'])->toBe('read_orders,write_orders,read_fulfillments,read_customers')
            ->and($q['redirect_uri'])->toBe('https://api.trik.test/webhooks/shopify/oauth/callback')
            ->and(strlen($q['state']))->toBe(40)
            ->and(isset($q['grant_options']))->toBeFalse(); // offline token
    });

    it('lets only members who manage stores connect, and only real shop addresses', function (): void {
        $agent = teammate($this->ws, WorkspaceRole::Agent);
        inboxAs($agent['headers'])->postJson('/v1/commerce/shopify/install', ['shop' => 'laila'])->assertForbidden();
        inboxAs($agent['headers'])->getJson('/v1/commerce/stores')->assertForbidden();
        inboxAs($this->owner['headers'])->postJson('/v1/commerce/shopify/install', ['shop' => 'laila.com'])->assertUnprocessable()->assertJsonPath('code', 'validation-failed');
        inboxAs($this->owner['headers'])->postJson('/v1/commerce/shopify/install', ['shop' => 'x.myshopify.com.evil.io'])->assertUnprocessable();

        config(['commerce.shopify.client_secret' => '']);
        inboxAs($this->owner['headers'])->postJson('/v1/commerce/shopify/install', ['shop' => 'laila'])->assertStatus(503)->assertJsonPath('code', 'shopify-not-configured');

        config(['trik.features.commerce' => 'none']);
        inboxAs($this->owner['headers'])->getJson('/v1/commerce/stores')->assertNotFound();
    });

    it('completes the grant: token stored encrypted, store active, 60 days of orders imported', function (): void {
        $state = startInstall($this->owner['headers']);
        $this->jsonl = '';

        $this->get('/webhooks/shopify/oauth/callback?'.signedQuery(['code' => 'c0de', 'shop' => SHOP, 'state' => $state, 'host' => 'abc']))
            ->assertRedirectContains('https://app.trik.test/settings/stores?shopify=connected&store=');

        $store = inWs($this->ws, fn () => Store::query()->sole());
        $raw = inWs($this->ws, fn () => DB::table('commerce_stores')->value('access_token'));

        expect($store->status)->toBe(StoreStatus::Active)
            ->and($store->access_token)->toBe('shpat_from_shopify')
            ->and($raw)->toBeString()->not->toContain('shpat_from_shopify')
            ->and($store->toArray())->not->toHaveKey('access_token')
            ->and($store->name)->toBe('Laila Fashion')
            ->and($store->installed_by)->toBe($this->owner['user']->id)
            ->and($store->backfill['status'])->toBe('done') // no orders in the window
            ->and(now()->diffInDays($store->backfill['since'], true))->toBeLessThan(61); // no read_all_orders: Shopify serves 60 days

        inboxAs($this->owner['headers'])->getJson('/v1/commerce/stores')->assertOk()
            ->assertJsonPath('data.0.shop_domain', SHOP)->assertJsonPath('data.0.status', 'active')
            ->assertJsonMissingPath('data.0.access_token');
    });

    it('refuses every tampered or replayed callback, and never calls a foreign host', function (): void {
        $refused = fn (string $query, string $code) => $this->get('/webhooks/shopify/oauth/callback?'.$query)
            ->assertRedirect('https://app.trik.test/settings/stores?shopify_error='.$code);

        $state = startInstall($this->owner['headers']);
        $refused(signedQuery(['code' => 'c', 'shop' => SHOP, 'state' => $state], 'wrong-secret'), 'invalid-signature');
        $refused(signedQuery(['code' => 'c', 'shop' => SHOP.'.evil.io', 'state' => $state]), 'invalid-shop');
        $refused(signedQuery(['code' => 'c', 'shop' => SHOP, 'state' => $state, 'timestamp' => (string) (time() - 3600)]), 'expired');
        $refused(signedQuery(['code' => 'c', 'shop' => 'other.myshopify.com', 'state' => $state]), 'shop-mismatch');
        // the state was used up by the mismatch: single use
        $refused(signedQuery(['code' => 'c', 'shop' => SHOP, 'state' => $state]), 'expired');
        $refused(signedQuery(['code' => 'c', 'shop' => SHOP, 'state' => 'made-up']), 'expired');

        // demoted between "Connect" and Shopify's redirect
        $admin = teammate($this->ws, WorkspaceRole::Admin);
        $state = startInstall($admin['headers']);
        WorkspaceMember::query()->where('user_id', $admin['user']->id)->update(['role' => WorkspaceRole::Agent]);
        $refused(signedQuery(['code' => 'c', 'shop' => SHOP, 'state' => $state]), 'forbidden');

        // the seller pressed Cancel on Shopify's consent screen
        $state = startInstall($this->owner['headers']);
        $refused(signedQuery(['shop' => SHOP, 'state' => $state, 'error' => 'access_denied']), 'denied');

        expect($this->calls)->toBe([])
            ->and(inWs($this->ws, fn () => DB::table('commerce_stores')->count()))->toBe(0);
    });

    it('keeps a shop in one workspace at a time', function (): void {
        connectedStore($this->ws);
        $other = ownerWithWorkspace('Someone else');

        inboxAs($other['headers'])->postJson('/v1/commerce/shopify/install', ['shop' => 'laila-fashion'])
            ->assertConflict()->assertJsonPath('code', 'store-connected-elsewhere');

        // a state issued before the first workspace connected
        $mine = ownerWithWorkspace('Third');
        inWs($this->ws, fn () => DB::table('commerce_stores')->update(['status' => 'disconnected']));
        $state = startInstall($mine['headers']);
        inWs($this->ws, fn () => DB::table('commerce_stores')->update(['status' => 'active']));
        $this->get('/webhooks/shopify/oauth/callback?'.signedQuery(['code' => 'c', 'shop' => SHOP, 'state' => $state]))
            ->assertRedirect('https://app.trik.test/settings/stores?shopify_error=store-connected-elsewhere');
    });

    it('sends a seller arriving from Shopify to the web app to pick a workspace', function (): void {
        $this->get('/webhooks/shopify/app?'.signedQuery(['shop' => SHOP, 'host' => 'abc']))
            ->assertRedirect('https://app.trik.test/settings/stores?connect=shopify&shop='.urlencode(SHOP));
        $this->get('/webhooks/shopify/app?'.signedQuery(['shop' => SHOP], 'nope'))
            ->assertRedirect('https://app.trik.test/settings/stores?shopify_error=invalid-signature');
    });

    it('disconnects: token dropped, webhooks no longer routed, and other workspaces cannot touch it', function (): void {
        $store = connectedStore($this->ws);
        $other = ownerWithWorkspace('Other');

        inboxAs($other['headers'])->deleteJson("/v1/commerce/stores/{$store->id}")->assertNotFound();
        inboxAs($other['headers'])->postJson("/v1/commerce/stores/{$store->id}/backfill", [], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
        inboxAs($other['headers'])->getJson('/v1/commerce/stores')->assertOk()->assertJsonCount(0, 'data');

        inboxAs($this->owner['headers'])->deleteJson("/v1/commerce/stores/{$store->id}")->assertNoContent();
        expect(inWs($this->ws, fn () => Store::query()->sole())->only(['status', 'access_token']))->toBe(['status' => StoreStatus::Disconnected, 'access_token' => null]);

        shopifyHook('orders/create', webhookOrder())->assertOk()->assertJsonPath('ignored', 'not-connected');
        expect(inWs($this->ws, fn () => DB::table('orders_orders')->count()))->toBe(0);
    });
});

describe('webhooks', function (): void {
    beforeEach(function (): void {
        $this->store = connectedStore($this->ws);
    });

    it('records a new order once, in its workspace, however often Shopify delivers it', function (): void {
        $other = ownerWithWorkspace('Other');
        shopifyHook('orders/create', webhookOrder(), id: 'wh-1')->assertOk();
        shopifyHook('orders/create', webhookOrder(), id: 'wh-1')->assertOk(); // Shopify retry

        inWs($this->ws, function (): void {
            $order = Order::query()->sole();
            expect($order->number)->toBe('#1048')
                ->and($order->source->value)->toBe('shopify')
                ->and($order->external_id)->toBe('5820117942336')
                ->and($order->total)->toBe(146000)
                ->and($order->customer_phone)->toBe('201012345678')
                ->and(StoreWebhookEvent::query()->sole()->outcome)->toBe('recorded');
        });
        expect(inWs($other['workspace'], fn () => Order::query()->count()))->toBe(0);
    });

    it('refuses unsigned or wrongly signed deliveries, and ignores topics Trik does not use', function (): void {
        shopifyHook('orders/create', webhookOrder(), secret: 'attacker')->assertUnauthorized()->assertJsonPath('code', 'invalid-signature');
        $this->flushHeaders()->call('POST', '/webhooks/shopify', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SHOPIFY_TOPIC' => 'orders/create', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => SHOP], '{}')
            ->assertUnauthorized();
        shopifyHook('products/update', ['id' => 1])->assertOk()->assertJsonPath('ignored', 'topic');
        shopifyHook('orders/create', webhookOrder(), shop: 'evil.com')->assertStatus(400);
        shopifyHook('orders/create', webhookOrder(), shop: 'unknown.myshopify.com')->assertOk()->assertJsonPath('ignored', 'not-connected');

        expect(inWs($this->ws, fn () => DB::table('commerce_webhook_events')->count()))->toBe(0);
    });

    it('never lets an older payload roll an order back', function (): void {
        $now = now()->subMinutes(5);
        shopifyHook('orders/updated', webhookOrder(['updated_at' => $now->toIso8601String(), 'current_total_price' => '1460.00']))->assertOk();
        shopifyHook('orders/updated', webhookOrder(['updated_at' => $now->copy()->subMinute()->toIso8601String(), 'current_total_price' => '999.00']))->assertOk();

        inWs($this->ws, function (): void {
            expect(Order::query()->sole()->total)->toBe(146000)
                ->and(StoreWebhookEvent::query()->orderBy('created_at')->pluck('outcome')->all())->toBe(['recorded', 'stale']);
        });
    });

    it('follows cancellations, keeps orders without a phone out, and notes why', function (): void {
        shopifyHook('orders/create', webhookOrder())->assertOk();
        shopifyHook('orders/cancelled', webhookOrder(['cancelled_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()]))->assertOk();
        $noPhone = webhookOrder(['id' => 1, 'name' => '#1049']);
        $noPhone['shipping_address']['phone'] = null;
        shopifyHook('orders/create', $noPhone)->assertOk();

        inWs($this->ws, function (): void {
            expect(Order::query()->sole()->status->value)->toBe('cancelled')
                ->and(StoreWebhookEvent::query()->orderBy('created_at')->pluck('outcome')->all())->toBe(['recorded', 'recorded', 'skipped:no-phone']);
        });
    });

    it('re-reads the order through GraphQL after a fulfillment and takes the tracking', function (): void {
        shopifyHook('orders/create', webhookOrder())->assertOk();
        $this->refreshNode = [
            'id' => 'gid://shopify/Order/5820117942336', 'legacyResourceId' => '5820117942336', 'name' => '#1048',
            'createdAt' => now()->subMinutes(5)->toIso8601String(), 'updatedAt' => now()->toIso8601String(), 'cancelledAt' => null,
            'displayFinancialStatus' => 'PENDING', 'displayFulfillmentStatus' => 'FULFILLED', 'currencyCode' => 'EGP',
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '1460.0']], 'totalShippingPriceSet' => ['shopMoney' => ['amount' => '60.0']],
            'currentTotalDiscountsSet' => ['shopMoney' => ['amount' => '100.0']],
            'shippingAddress' => ['name' => 'منى عادل', 'phone' => '01012345678', 'address1' => '12 شارع التحرير', 'city' => 'الدقي', 'provinceCode' => 'GZ', 'countryCodeV2' => 'EG'],
            'fulfillments' => [['trackingInfo' => [['company' => 'Bosta', 'number' => 'BST-1', 'url' => 'https://bosta.co/t/BST-1']]]],
            'lineItems' => ['edges' => [['node' => ['id' => 'gid://shopify/LineItem/14001', 'title' => 'فستان صيفي', 'quantity' => 2, 'currentQuantity' => 2, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '450.0']]]],
                ['node' => ['id' => 'gid://shopify/LineItem/14002', 'title' => 'حزام جلد', 'quantity' => 1, 'currentQuantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '600.0']]]]]],
        ];

        shopifyHook('fulfillments/create', ['id' => 99, 'order_id' => 5820117942336, 'status' => 'success', 'tracking_company' => 'Bosta'])->assertOk();

        inWs($this->ws, function (): void {
            $order = Order::query()->sole();
            expect([$order->status->value, $order->courier, $order->tracking_number])->toBe(['shipped', 'Bosta', 'BST-1']);
        });
    });

    it('marks the store uninstalled when the seller removes the app, or the token stops working', function (): void {
        shopifyHook('app/uninstalled', ['id' => 1, 'domain' => SHOP])->assertOk();
        expect(inWs($this->ws, fn () => Store::query()->sole())->only(['status', 'access_token']))->toBe(['status' => StoreStatus::Uninstalled, 'access_token' => null]);

        // reconnecting brings it back; then Shopify refuses the token on a refresh
        inWs($this->ws, fn () => Store::query()->sole()->update(['status' => StoreStatus::Active, 'access_token' => 'shpat_old']));
        $this->refreshNode = fn () => Http::response(['errors' => '[API] Invalid API key or access token'], 401);
        shopifyHook('fulfillments/update', ['id' => 99, 'order_id' => 123])->assertOk();

        expect(inWs($this->ws, fn () => Store::query()->sole())->status)->toBe(StoreStatus::Uninstalled);
    });
});

describe('backfill', function (): void {
    it('imports the window from a bulk operation, items and late items included, without messaging anyone', function (): void {
        // confirmation is on: old orders must still not be asked about
        inWs($this->ws, fn () => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']));
        inboxAs($this->owner['headers'])->putJson('/v1/confirmation/settings', ['enabled' => true, 'template_name' => 'order_confirmation', 'template_language' => 'ar'])->assertOk();

        $order = fn (int $id, string $phone, int $daysAgo) => json_encode([
            'id' => "gid://shopify/Order/{$id}", 'legacyResourceId' => (string) $id, 'name' => "#{$id}",
            'createdAt' => now()->subDays($daysAgo)->toIso8601String(), 'updatedAt' => now()->subDays($daysAgo)->toIso8601String(),
            'displayFinancialStatus' => 'PENDING', 'displayFulfillmentStatus' => 'UNFULFILLED', 'currencyCode' => 'EGP',
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '500.0']],
            'shippingAddress' => ['name' => 'Customer', 'phone' => $phone, 'address1' => 'شارع ٩', 'city' => 'المعادي', 'provinceCode' => 'C', 'countryCodeV2' => 'EG'],
        ]);
        $item = fn (int $id, int $parent) => json_encode(['id' => "gid://shopify/LineItem/{$id}", 'title' => 'قميص', 'quantity' => 1, 'currentQuantity' => 1,
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '500.0']], '__parentId' => "gid://shopify/Order/{$parent}"]);

        $this->jsonl = implode("\n", [
            $order(1, '01011111111', 30), $item(11, 1),
            $order(2, '01022222222', 20), $item(21, 2), $item(22, 2),
            $item(12, 1),                                          // order 1's second item, after order 2's
            $order(3, '', 10), $item(31, 3),                       // no phone: skipped
        ])."\n";

        $store = connectedStore($this->ws, scopes: 'read_orders,read_all_orders');
        inboxAs($this->owner['headers'])->postJson("/v1/commerce/stores/{$store->id}/backfill", [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(202);

        inWs($this->ws, function (): void {
            $store = Store::query()->sole();
            expect($store->backfill['status'])->toBe('done')
                ->and($store->backfill['count'])->toBe(2)
                ->and($store->backfill['skipped'])->toBe(1)
                ->and(now()->diffInDays($store->backfill['since'], true))->toBeGreaterThan(89) // read_all_orders: 90 days
                ->and(Order::query()->orderBy('number')->pluck('number')->all())->toBe(['#1', '#2'])
                ->and(Order::query()->where('number', '#1')->sole()->subtotal)->toBe(100000)
                ->and(Order::query()->where('number', '#2')->sole()->subtotal)->toBe(100000)
                ->and(ConfirmationRun::query()->count())->toBe(0);
        });
        Queue::assertNotPushed(SendOutgoingMessage::class);
    });

    it('is refused while one runs, and needs an idempotency key', function (): void {
        $store = connectedStore($this->ws);
        inWs($this->ws, fn () => $store->update(['backfill' => ['status' => 'running']]));

        inboxAs($this->owner['headers'])->postJson("/v1/commerce/stores/{$store->id}/backfill")->assertStatus(400);
        inboxAs($this->owner['headers'])->postJson("/v1/commerce/stores/{$store->id}/backfill", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'backfill-running');
    });
});

describe('cost throttle', function (): void {
    it('waits a moment for a few points, and hands a long wait back to the queue', function (): void {
        Sleep::fake();
        $throttle = new CostThrottle;
        $throttle->reserve(SHOP, 500); // nothing known yet: goes

        $throttle->record(SHOP, ['maximumAvailable' => 2000.0, 'currentlyAvailable' => 50, 'restoreRate' => 100.0]);
        $throttle->reserve(SHOP, 150); // 100 points short: one second
        Sleep::assertSleptTimes(1);

        $throttle->record(SHOP, ['maximumAvailable' => 2000.0, 'currentlyAvailable' => 0, 'restoreRate' => 100.0]);
        try {
            $throttle->reserve(SHOP, 1000);
            $this->fail('expected a retry');
        } catch (ShopifyCallFailed $e) {
            expect($e->retryAfter)->toBe(10)->and($e->unauthorized)->toBeFalse();
        }
    });

    it('turns a THROTTLED answer into a retry instead of an error', function (): void {
        $this->refreshNode = fn () => Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            'extensions' => ['cost' => ['throttleStatus' => ['maximumAvailable' => 1000.0, 'currentlyAvailable' => 10, 'restoreRate' => 50.0]]]]);

        try {
            app(ShopifyAdminClient::class)->graphql(ShopDomain::fromShopify(SHOP), 't', ShopifyQueries::order(), ['id' => 'gid://shopify/Order/1'], 300);
            $this->fail('expected a retry');
        } catch (ShopifyCallFailed $e) {
            expect($e->retryAfter)->toBe(6);
        }
    });
});
