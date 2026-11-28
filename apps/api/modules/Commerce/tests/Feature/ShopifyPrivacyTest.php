<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Compliance\Infrastructure\Models\PrivacyRequest;
use Modules\Contacts\Infrastructure\Models\Contact;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\Workspaces\Domain\WorkspaceRole;

require_once dirname(__DIR__).'/ShopifyHelpers.php';

function privacyHook(string $topic, array $payload, ?string $id = null, string $secret = 'shh'): TestResponse
{
    return shopifyHook($topic, ['shop_id' => 954889, 'shop_domain' => SHOP, ...$payload], id: $id, secret: $secret, path: '/webhooks/shopify/compliance');
}

/** The order the fixture describes (Mona, 01012345678), plus another customer's. */
function twoShopifyOrders(): void
{
    shopifyHook('orders/create', webhookOrder())->assertOk();
    $other = webhookOrder(['id' => 5820117940000, 'name' => '#1049']);
    $other['shipping_address'] = [...$other['shipping_address'], 'phone' => '01098765432', 'name' => 'نور طارق'];
    shopifyHook('orders/create', $other)->assertOk();
}

function auditActions(string $ws): array
{
    return inWs($ws, fn () => DB::table('workspaces_audit_events')->where('action', 'like', 'compliance.%')->orderBy('created_at')->pluck('action')->all());
}

beforeEach(function (): void {
    config(['commerce.shopify.client_id' => 'cid', 'commerce.shopify.client_secret' => 'shh', 'trik.features.commerce' => 'all']);
    Queue::fake([SendOutgoingMessage::class]);
    Http::preventStrayRequests();
    // cancelling a Shopify order in Trik tags it in Shopify (write-back)
    Http::fake(['*.myshopify.com/*' => gqlResponse(['tagsAdd' => ['userErrors' => []], 'tagsRemove' => ['userErrors' => []]])]);

    $this->owner = ownerWithWorkspace('Laila Fashion');
    $this->ws = $this->owner['workspace'];
    $this->store = connectedStore($this->ws);
});

it('compiles a data request for the store owner, from the orders it names', function (): void {
    twoShopifyOrders();

    privacyHook('customers/data_request', [
        'orders_requested' => [5820117942336], 'customer' => ['id' => 191167, 'email' => 'mona@example.com', 'phone' => null], 'data_request' => ['id' => 9999],
    ], id: 'dr-1')->assertOk();

    $list = inboxAs($this->owner['headers'])->getJson('/v1/compliance/requests')->assertOk()
        ->assertJsonPath('data.0.kind', 'data_request')->assertJsonPath('data.0.status', 'done')
        ->assertJsonPath('data.0.origin_ref', SHOP)->assertJsonPath('data.0.export_available', true)
        ->assertJsonPath('data.0.result.orders', 1)->assertJsonPath('data.0.result.contacts', 1)
        ->assertJsonMissingPath('data.0.subject');
    $id = $list->json('data.0.id');

    $export = inboxAs($this->owner['headers'])->getJson("/v1/compliance/requests/{$id}/export")->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="trik-data-request-'.$id.'.json"')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($export->json('data.orders'))->toHaveCount(1)
        ->and($export->json('data.orders.0.number'))->toBe('#1048')
        ->and($export->json('data.orders.0.customer_phone'))->toBe('201012345678') // reached through the order
        ->and($export->json('data.orders.0.items'))->toHaveCount(2)
        ->and($export->json('data.contacts.0.name'))->toBe('منى عادل')
        ->and(auditActions($this->ws))->toBe(['compliance.request_received', 'compliance.data_exported', 'compliance.export_downloaded']);

    // personal data: owners and admins only, never another workspace
    $agent = teammate($this->ws, WorkspaceRole::Agent);
    inboxAs($agent['headers'])->getJson("/v1/compliance/requests/{$id}/export")->assertForbidden();
    inboxAs($agent['headers'])->getJson('/v1/compliance/requests')->assertForbidden();
    $other = ownerWithWorkspace('Other');
    inboxAs($other['headers'])->getJson("/v1/compliance/requests/{$id}/export")->assertNotFound();
    inboxAs($other['headers'])->getJson('/v1/compliance/requests')->assertOk()->assertJsonCount(0, 'data');

    // stored encrypted, and gone once expired
    $raw = inWs($this->ws, fn () => DB::table('compliance_requests')->value('export'));
    expect($raw)->toBeString()->not->toContain('201012345678');
    inWs($this->ws, fn () => PrivacyRequest::query()->update(['export_expires_at' => now()->subMinute()]));
    inboxAs($this->owner['headers'])->getJson("/v1/compliance/requests/{$id}/export")->assertNotFound()->assertJsonPath('code', 'export-not-available');
});

it("redacts a customer: the store's orders anonymised, the contact's name, timeline reasons and raw deliveries gone", function (): void {
    twoShopifyOrders();
    $orderId = inWs($this->ws, fn () => Order::query()->where('number', '#1048')->value('id'));
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$orderId}/cancel", ['reason' => 'منى قالت إنها مسافرة'])->assertOk();
    // the same person ordered once on WhatsApp too: the seller's own record, not the store's
    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => (string) Str::uuid()])->postJson('/v1/orders', [
        'customer_phone' => '01012345678', 'customer_name' => 'منى', 'items' => [['title' => 'x', 'quantity' => 1, 'unit_price' => 100]],
    ])->assertCreated();

    privacyHook('customers/redact', ['customer' => ['id' => 191167, 'email' => 'mona@example.com', 'phone' => '+20 101 234 5678'], 'orders_to_redact' => []], id: 'cr-1')->assertOk();
    privacyHook('customers/redact', ['customer' => ['id' => 191167, 'phone' => '+20 101 234 5678'], 'orders_to_redact' => []], id: 'cr-1')->assertOk(); // retry

    inWs($this->ws, function (): void {
        $redacted = Order::query()->where('number', '#1048')->sole();
        $request = PrivacyRequest::query()->sole();

        expect([$redacted->customer_name, $redacted->customer_phone, $redacted->address, $redacted->note])->toBe([null, '', [], null])
            ->and($redacted->total)->toBe(146000) // the books stay
            ->and(Order::query()->where('number', '#1049')->value('customer_phone'))->toBe('201098765432')
            ->and(Order::query()->where('source', 'chat')->value('customer_phone'))->toBe('201012345678')
            ->and(Contact::query()->where('phone', '201012345678')->value('name'))->toBeNull()
            ->and(Contact::query()->where('phone', '201098765432')->value('name'))->toBe('نور طارق')
            ->and(DB::table('orders_events')->where('order_id', $redacted->id)->whereRaw("jsonb_exists(data, 'reason')")->count())->toBe(0)
            ->and(DB::table('commerce_webhook_events')->where('payload->id', 5820117942336)->count())->toBe(0)
            ->and(DB::table('commerce_webhook_events')->count())->toBe(1)
            ->and($request->status)->toBe('done')
            ->and($request->result)->toMatchArray(['orders' => 1, 'contacts' => 1])
            ->and($request->subject)->toBe(['done' => true]);
    });
    expect(auditActions($this->ws))->toBe(['compliance.request_received', 'compliance.data_redacted']);
});

it('redacts a whole shop 48 hours after an uninstall, but not one that came back', function (): void {
    twoShopifyOrders();
    privacyHook('shop/redact', [], id: 'sr-0')->assertOk();
    expect(inWs($this->ws, fn () => PrivacyRequest::query()->count()))->toBe(0); // still installed: nothing to do

    shopifyHook('app/uninstalled', ['id' => 1, 'domain' => SHOP])->assertOk();
    privacyHook('shop/redact', [], id: 'sr-1')->assertOk();

    inWs($this->ws, function (): void {
        expect(Order::query()->where('source', 'shopify')->pluck('customer_phone')->all())->toBe(['', ''])
            ->and(Contact::query()->whereNotNull('name')->count())->toBe(0)
            ->and(Store::query()->count())->toBe(0)
            ->and(DB::table('commerce_webhook_events')->count())->toBe(0)
            ->and(DB::table('commerce_order_versions')->count())->toBe(0)
            ->and(PrivacyRequest::query()->sole()->result)->toMatchArray(['orders' => 2, 'contacts' => 2]);
    });
});

it('reaches every workspace that held the shop, and only those', function (): void {
    twoShopifyOrders();
    inboxAs($this->owner['headers'])->deleteJson("/v1/commerce/stores/{$this->store->id}")->assertNoContent();
    $next = ownerWithWorkspace('New owner');
    connectedStore($next['workspace']);
    $bystander = ownerWithWorkspace('Bystander');

    privacyHook('customers/redact', ['customer' => ['phone' => '01012345678'], 'orders_to_redact' => [5820117942336]], id: 'cr-2')->assertOk();

    expect(inWs($this->ws, fn () => PrivacyRequest::query()->count()))->toBe(1)
        ->and(inWs($next['workspace'], fn () => PrivacyRequest::query()->count()))->toBe(1)
        ->and(inWs($bystander['workspace'], fn () => PrivacyRequest::query()->count()))->toBe(0)
        ->and(inWs($this->ws, fn () => Order::query()->where('number', '#1048')->value('customer_phone')))->toBe('');
});

it('refuses unsigned privacy webhooks and ignores other topics', function (): void {
    privacyHook('customers/redact', ['customer' => ['phone' => '01012345678']], secret: 'attacker')->assertUnauthorized()->assertJsonPath('code', 'invalid-signature');
    privacyHook('orders/create', ['id' => 1])->assertOk()->assertJsonPath('ignored', 'topic');
    shopifyHook('shop/redact', ['shop_domain' => SHOP], shop: 'evil.io', path: '/webhooks/shopify/compliance')->assertStatus(400);
    // an unknown shop is acknowledged (Shopify tests the endpoint with made-up shops)
    shopifyHook('shop/redact', ['shop_domain' => 'never-installed.myshopify.com'], shop: 'never-installed.myshopify.com', path: '/webhooks/shopify/compliance')->assertOk();

    expect(inWs($this->ws, fn () => PrivacyRequest::query()->count()))->toBe(0);
});
