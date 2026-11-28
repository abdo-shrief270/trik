<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\Workspaces\Domain\WorkspaceRole;

require_once dirname(__DIR__).'/ShopifyHelpers.php';

/** The GraphQL operations Trik sent, in order: [operation, variables]. */
function sentOps(array $calls): array
{
    return array_map(fn (array $c): string => $c[0], $calls);
}

beforeEach(function (): void {
    config(['commerce.shopify.client_id' => 'cid', 'commerce.shopify.client_secret' => 'shh', 'trik.features.commerce' => 'all']);
    Queue::fake([SendOutgoingMessage::class]);

    $this->owner = ownerWithWorkspace('Laila Fashion');
    $this->ws = $this->owner['workspace'];
    $this->store = connectedStore($this->ws);
    $this->gql = [];
    $this->shopifyAddress = ['firstName' => 'منى', 'lastName' => 'عادل', 'phone' => '+201012345678', 'zip' => null, 'company' => null, 'countryCodeV2' => 'EG'];
    $this->cancelledAt = null;
    $this->answer = null;

    $test = $this;
    Http::fake(function (Request $r) use ($test) {
        $q = (string) $r['query'];
        $op = match (true) {
            str_contains($q, 'tagsAdd') => 'tagsAdd',
            str_contains($q, 'tagsRemove') => 'tagsRemove',
            str_contains($q, 'orderCancel') => 'orderCancel',
            str_contains($q, 'orderUpdate') => 'orderUpdate',
            str_contains($q, 'shippingAddress { firstName') => 'readAddress',
            default => 'other',
        };
        $test->gql[] = [$op, $r['variables'] ?? []];
        if ($test->answer instanceof Closure) {
            return ($test->answer)($op);
        }

        return gqlResponse(match ($op) {
            'tagsAdd' => ['tagsAdd' => ['userErrors' => []]],
            'tagsRemove' => ['tagsRemove' => ['userErrors' => []]],
            'orderCancel' => ['orderCancel' => ['job' => ['id' => 'gid://shopify/Job/1'], 'orderCancelUserErrors' => []]],
            'orderUpdate' => ['orderUpdate' => ['order' => ['id' => 'x'], 'userErrors' => []]],
            'readAddress' => ['order' => ['cancelledAt' => $test->cancelledAt, 'shippingAddress' => $test->shopifyAddress]],
            default => [],
        });
    });

    shopifyHook('orders/create', webhookOrder())->assertOk();
    $this->order = inWs($this->ws, fn () => Order::query()->sole());
});

it('tags the order in Shopify when Trik confirms it, and notes it on the timeline', function (): void {
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$this->order->id}/confirm")->assertOk();

    expect($this->gql)->toBe([
        ['tagsAdd', ['id' => 'gid://shopify/Order/5820117942336', 'tags' => ['trik-confirmed']]],
        ['tagsRemove', ['id' => 'gid://shopify/Order/5820117942336', 'tags' => ['trik-cancelled']]],
    ]);
    $event = inWs($this->ws, fn () => DB::table('orders_events')->where('order_id', $this->order->id)->where('type', 'store_updated')->value('data'));
    expect(json_decode((string) $event, true))->toEqual(['store' => 'shopify', 'what' => ['tag:trik-confirmed']]);
});

it('cancels in Shopify only when the seller opted in, and never twice', function (): void {
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$this->order->id}/cancel", ['reason' => 'duplicate'])->assertOk();
    expect(sentOps($this->gql))->toBe(['tagsAdd', 'tagsRemove']);

    // opted in, on another order; Shopify already cancelled a third one
    inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['cancel_in_store' => true]])
        ->assertOk()->assertJsonPath('data.settings', ['tag_orders' => true, 'write_address' => true, 'cancel_in_store' => true]);
    shopifyHook('orders/create', webhookOrder(['id' => 5820117940001, 'name' => '#1050']))->assertOk();
    $second = inWs($this->ws, fn () => Order::query()->where('number', '#1050')->value('id'));
    $this->gql = [];
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$second}/cancel", [])->assertOk();

    expect(sentOps($this->gql))->toBe(['tagsAdd', 'tagsRemove', 'readAddress', 'orderCancel'])
        ->and($this->gql[3][1])->toBe(['orderId' => 'gid://shopify/Order/5820117940001', 'staffNote' => 'Cancelled in Trik']);

    shopifyHook('orders/create', webhookOrder(['id' => 5820117940002, 'name' => '#1051']))->assertOk();
    $third = inWs($this->ws, fn () => Order::query()->where('number', '#1051')->value('id'));
    $this->gql = [];
    $this->cancelledAt = now()->toIso8601String();
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$third}/cancel", [])->assertOk();
    expect(sentOps($this->gql))->toBe(['tagsAdd', 'tagsRemove', 'readAddress']);
});

it("writes a fixed address back, keeping Shopify's name and phone, and doesn't loop on Shopify's echo", function (): void {
    inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$this->order->id}", ['address' => [
        'governorate' => 'GZ', 'area' => 'الدقي', 'street' => 'شارع التحرير', 'building' => '12', 'floor' => '3', 'apartment' => '7', 'landmark' => 'جنب البنك',
    ]])->assertOk();

    expect(sentOps($this->gql))->toBe(['readAddress', 'orderUpdate'])
        ->and($this->gql[1][1]['input'])->toEqual(['id' => 'gid://shopify/Order/5820117942336', 'shippingAddress' => [
            'firstName' => 'منى', 'lastName' => 'عادل', 'phone' => '+201012345678',
            'address1' => 'شارع التحرير، عمارة 12', 'address2' => 'الدور 3، شقة 7، جنب البنك',
            'city' => 'الدقي', 'provinceCode' => 'GZ', 'countryCode' => 'EG',
        ]]);

    // Shopify answers with orders/updated carrying the new address: a store change, never written back
    $this->gql = [];
    $echo = webhookOrder(['updated_at' => now()->addSecond()->toIso8601String()]);
    $echo['shipping_address'] = [...$echo['shipping_address'], 'address1' => 'شارع التحرير، عمارة 12', 'city' => 'الدقي'];
    shopifyHook('orders/updated', $echo)->assertOk();
    $echo['cancelled_at'] = now()->toIso8601String();
    $echo['updated_at'] = now()->addSeconds(2)->toIso8601String();
    shopifyHook('orders/cancelled', $echo)->assertOk();

    expect($this->gql)->toBe([])
        ->and(inWs($this->ws, fn () => Order::query()->find($this->order->id)->status->value))->toBe('cancelled');
});

it('leaves chat orders, disconnected stores and switched-off settings alone', function (): void {
    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => (string) Str::uuid()])->postJson('/v1/orders', [
        'customer_phone' => '01012345678', 'items' => [['title' => 'x', 'quantity' => 1, 'unit_price' => 100]],
    ])->assertCreated();
    $chat = inWs($this->ws, fn () => Order::query()->where('source', 'chat')->value('id'));
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$chat}/confirm")->assertOk();

    inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['tag_orders' => false, 'write_address' => false]])->assertOk();
    inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$this->order->id}", ['address' => ['governorate' => 'GZ', 'area' => 'الدقي', 'street' => 'التحرير']])->assertOk();
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$this->order->id}/confirm")->assertOk();

    shopifyHook('orders/create', webhookOrder(['id' => 5820117940003, 'name' => '#1052']))->assertOk();
    $later = inWs($this->ws, fn () => Order::query()->where('number', '#1052')->value('id'));
    inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['tag_orders' => true]])->assertOk();
    inboxAs($this->owner['headers'])->deleteJson("/v1/commerce/stores/{$this->store->id}")->assertNoContent();
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$later}/confirm")->assertOk();

    expect($this->gql)->toBe([]);
});

it('records what Shopify refused, and uninstalls the store when the token stopped working', function (): void {
    $this->answer = fn (string $op) => gqlResponse([$op => ['userErrors' => [['field' => ['tags'], 'message' => 'Tags is invalid']]]]);
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$this->order->id}/confirm")->assertOk();

    $failed = inWs($this->ws, fn () => DB::table('orders_events')->where('order_id', $this->order->id)->where('type', 'store_update_failed')->value('data'));
    expect(json_decode((string) $failed, true))->toEqual(['store' => 'shopify', 'what' => 'status', 'error' => 'Shopify refused tagsAdd: Tags is invalid']);

    shopifyHook('orders/create', webhookOrder(['id' => 5820117940004, 'name' => '#1053']))->assertOk();
    $other = inWs($this->ws, fn () => Order::query()->where('number', '#1053')->value('id'));
    $this->answer = fn () => Http::response(['errors' => 'Invalid API key or access token'], 401);
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$other}/cancel", [])->assertOk();

    expect(inWs($this->ws, fn () => Store::query()->sole())->status)->toBe(StoreStatus::Uninstalled);
});

it('lets only store managers change what is written back, in their own workspace', function (): void {
    $agent = teammate($this->ws, WorkspaceRole::Agent);
    inboxAs($agent['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['cancel_in_store' => true]])->assertForbidden();
    $other = ownerWithWorkspace('Other');
    inboxAs($other['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['cancel_in_store' => true]])->assertNotFound();
    inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['refund' => true]])->assertUnprocessable();
    inboxAs($this->owner['headers'])->patchJson("/v1/commerce/stores/{$this->store->id}", ['settings' => ['cancel_in_store' => 'yes please']])->assertUnprocessable();

    expect(inWs($this->ws, fn () => Store::query()->sole())->effectiveSettings())->toBe(['tag_orders' => true, 'write_address' => true, 'cancel_in_store' => false]);
});
