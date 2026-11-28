<?php

declare(strict_types=1);

use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\Orders\Application\Events\OrderConfirmed;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\Workspaces\Domain\WorkspaceRole;

function shopifyOrder(string $externalId = '5550001', ?OrderStatus $status = null, string $raw = '12 شارع التحرير، الدقي', int $qty = 2): OrderInput
{
    return new OrderInput(
        source: OrderSource::Shopify,
        externalId: $externalId,
        number: '#1048',
        customerPhone: '01012345678',
        customerName: 'Mona Adel',
        address: new Address(raw: $raw),
        items: [new OrderItemInput('فستان صيفي', $qty, 45000, 'DRS-01', 'M / أزرق'), new OrderItemInput('حزام', 1, 15000)],
        placedAt: now()->subHour(),
        shipping: 5000,
        status: $status,
    );
}

function postOrder(array $headers, array $body, string $key): TestResponse
{
    return inboxAs([...$headers, 'Idempotency-Key' => $key])->postJson('/v1/orders', $body);
}

beforeEach(function (): void {
    Queue::fake([SendOutgoingMessage::class]);
    $this->realtime = new RecordingRealtime;
    app()->instance(Realtime::class, $this->realtime);
    $this->owner = ownerWithWorkspace('Mona Store');
    $this->ws = $this->owner['workspace'];
    $this->ctx = app(TenantContext::class);
    $this->number = $this->ctx->run($this->ws, fn (): WhatsAppNumber => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']));
});

it('takes store orders idempotently: redelivery updates, status only moves forward', function (): void {
    $record = app(RecordOrder::class);
    $first = $this->ctx->run($this->ws, fn () => $record->handle(shopifyOrder()));
    $again = $this->ctx->run($this->ws, fn () => $record->handle(shopifyOrder(qty: 3, status: OrderStatus::Shipped)));
    $late = $this->ctx->run($this->ws, fn () => $record->handle(shopifyOrder(qty: 3, status: OrderStatus::Confirmed))); // an older webhook arriving late

    expect($again->id)->toBe($first->id)
        ->and($late->status)->toBe(OrderStatus::Shipped)
        ->and($late->shipped_at)->not->toBeNull();

    $this->ctx->run($this->ws, function () use ($first): void {
        $o = Order::query()->sole();
        expect($o->total)->toBe(3 * 45000 + 15000 + 5000)
            ->and($o->cod_amount)->toBe($o->total)
            ->and($o->customer_phone)->toBe('201012345678')
            ->and($o->items()->count())->toBe(2)
            ->and(DB::table('orders_events')->where('order_id', $first->id)->pluck('type')->all())->toBe(['created', 'status_changed']);
    });
});

it('shows the customer\'s orders on their conversation, with the next action', function (): void {
    deliver($this->number, customerWrites());
    $conversation = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0');
    $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(shopifyOrder()));
    // someone else's order is not on this card
    $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(new OrderInput(OrderSource::Shopify, '999', '#9', '01099999999', 'X', new Address(raw: 'x'), [new OrderItemInput('x', 1, 100)], now())));

    $card = inboxAs($this->owner['headers'])->getJson("/v1/inbox/conversations/{$conversation['id']}/orders")->assertOk()->json('data');

    expect($card)->toHaveCount(1)
        ->and($card[0])->toMatchArray([
            'number' => '#1048', 'source' => 'shopify', 'status' => 'new', 'next_action' => 'confirm',
            'contact_id' => $conversation['contact']['id'],
            'total' => ['amount' => 110000, 'currency' => 'EGP'],
            'cod_amount' => ['amount' => 110000, 'currency' => 'EGP'],
        ])
        ->and($card[0]['items'][0])->toMatchArray(['title' => 'فستان صيفي', 'variant' => 'M / أزرق', 'quantity' => 2, 'unit_price' => ['amount' => 45000, 'currency' => 'EGP']])
        ->and($card[0]['address'])->toMatchArray(['raw' => '12 شارع التحرير، الدقي', 'line' => '12 شارع التحرير، الدقي', 'needs_fix' => false]);
});

it('creates orders from chat, numbered per workspace', function (): void {
    deliver($this->number, customerWrites());
    $contact = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.contact.id');
    $body = ['contact_id' => $contact, 'items' => [['title' => 'طقم', 'quantity' => 1, 'unit_price' => 60000]], 'shipping' => 4000, 'discount' => 1000];

    $a = postOrder($this->owner['headers'], $body, 'o-1')->assertCreated()->json('data');
    $b = postOrder($this->owner['headers'], $body, 'o-2')->assertCreated()->json('data');
    postOrder($this->owner['headers'], $body, 'o-2')->assertCreated()->assertJsonPath('data.id', $b['id']); // same key: same order

    expect([$a['number'], $b['number']])->toBe(['1001', '1002'])
        ->and($a['total']['amount'])->toBe(63000)
        ->and($a['customer']['phone'])->toBe('201012345678')
        ->and($a['next_action'])->toBe('fix_address'); // no address at all yet

    postOrder($this->owner['headers'], ['customer_phone' => '010', 'items' => [['title' => 'x', 'quantity' => 1, 'unit_price' => 1]]], 'o-3')->assertUnprocessable();
    postOrder($this->owner['headers'], ['customer_phone' => '01011111111', 'items' => [['title' => 'x', 'quantity' => 1, 'unit_price' => 100]], 'discount' => 500], 'o-4')->assertUnprocessable();
});

it('confirms and cancels with the lifecycle rules, and announces the confirmation', function (): void {
    Event::fake([OrderConfirmed::class, OrderChanged::class]);
    $id = $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(shopifyOrder())->id);

    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$id}/confirm")->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.next_action', null);
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$id}/confirm")->assertOk(); // idempotent
    Event::assertDispatchedTimes(OrderConfirmed::class, 1);
    Event::assertDispatched(OrderConfirmed::class, fn (OrderConfirmed $e): bool => $e->toContract()['data'] === ['order_id' => $id, 'channel' => 'agent', 'confirmed_by' => $this->owner['user']->id]);

    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$id}/cancel", ['reason' => 'العميلة غيرت رأيها'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$id}/confirm")->assertConflict()->assertJsonPath('code', 'invalid-transition');

    $timeline = inboxAs($this->owner['headers'])->getJson("/v1/orders/{$id}/timeline")->json('data');
    expect(array_column($timeline, 'type'))->toBe(['created', 'status_changed', 'status_changed'])
        ->and($timeline[2]['data'])->toEqual(['from' => 'confirmed', 'to' => 'cancelled', 'channel' => 'agent', 'reason' => 'العميلة غيرت رأيها'])
        ->and($timeline[2]['actor_id'])->toBe($this->owner['user']->id);
});

it('lets agents flag and fix an address in structured form before confirming', function (): void {
    $agent = teammate($this->ws, WorkspaceRole::Agent);
    $id = $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(shopifyOrder(raw: 'جنب الجامع'))->id);

    inboxAs($agent['headers'])->patchJson("/v1/orders/{$id}", ['address_needs_fix' => true])->assertOk()
        ->assertJsonPath('data.next_action', 'fix_address');
    inboxAs($agent['headers'])->postJson("/v1/orders/{$id}/confirm")->assertConflict()->assertJsonPath('code', 'address-needs-fix');
    // Generated clients send booleans as true/false in the query string; both spellings work.
    foreach (['1', 'true'] as $flag) {
        expect(inboxAs($agent['headers'])->getJson("/v1/orders?needs_address_fix={$flag}")->json('data'))->toHaveCount(1)
            ->and(inboxAs($agent['headers'])->getJson("/v1/orders?needs_address_fix={$flag}")->json('data.0.id'))->toBe($id);
    }
    expect(inboxAs($agent['headers'])->getJson('/v1/orders?needs_address_fix=false')->json('data'))->toHaveCount(1);
    inboxAs($agent['headers'])->getJson('/v1/orders?needs_address_fix=maybe')->assertUnprocessable();

    inboxAs($agent['headers'])->patchJson("/v1/orders/{$id}", ['address' => ['governorate' => 'GZ', 'area' => 'الدقي']])
        ->assertUnprocessable()->assertJsonPath('code', 'address-incomplete');
    inboxAs($agent['headers'])->patchJson("/v1/orders/{$id}", ['address' => ['governorate' => 'XX', 'area' => 'a', 'street' => 'b']])->assertUnprocessable();

    $fixed = inboxAs($agent['headers'])->patchJson("/v1/orders/{$id}", ['address' => [
        'governorate' => 'GZ', 'area' => 'الدقي', 'street' => 'شارع التحرير', 'building' => '12', 'landmark' => 'بجوار الجامع',
    ]])->assertOk()->json('data');

    expect($fixed['address'])->toMatchArray(['raw' => 'جنب الجامع', 'governorate' => 'GZ', 'needs_fix' => false, 'line' => '12 شارع التحرير، الدقي، الجيزة — بجوار الجامع'])
        ->and($fixed['next_action'])->toBe('confirm');

    // a later store update does not overwrite the agent's address
    $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(shopifyOrder(raw: 'جنب الجامع', qty: 5)));
    expect(inboxAs($agent['headers'])->getJson("/v1/orders/{$id}")->json('data.address.street'))->toBe('شارع التحرير');

    inboxAs($agent['headers'])->postJson("/v1/orders/{$id}/confirm")->assertOk();
});

it('pushes order changes to the open inbox', function (): void {
    $order = $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(shopifyOrder()));

    expect(collect($this->realtime->sent)->firstWhere('data.type', 'order.updated'))->toMatchArray([
        'channel' => 'inbox:'.$this->ws,
        'data' => ['type' => 'order.updated', 'id' => $order->id, 'contact_id' => $order->contact_id, 'status' => 'new', 'next_action' => 'confirm'],
    ]);
});

it('keeps orders inside their workspace and the timeline append-only', function (): void {
    deliver($this->number, customerWrites());
    $conversation = inboxAs($this->owner['headers'])->getJson('/v1/inbox/conversations')->json('data.0.id');
    $id = $this->ctx->run($this->ws, fn () => app(RecordOrder::class)->handle(shopifyOrder())->id);
    $stranger = ownerWithWorkspace('Other');

    foreach ([['get', "/v1/orders/{$id}"], ['patch', "/v1/orders/{$id}"], ['post', "/v1/orders/{$id}/confirm"], ['post', "/v1/orders/{$id}/cancel"], ['get', "/v1/orders/{$id}/timeline"], ['get', "/v1/inbox/conversations/{$conversation}/orders"]] as [$method, $url]) {
        inboxAs($stranger['headers'])->json($method, $url, ['note' => 'x'])->assertNotFound();
    }
    expect(inboxAs($stranger['headers'])->getJson('/v1/orders')->json('data'))->toBe([]);

    $this->ctx->run($this->ws, function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('orders_events')->update(['type' => 'forged'])))->toThrow(QueryException::class);
    });
});

it('lists Egypt\'s governorates for the address form', function (): void {
    $list = inboxAs($this->owner['headers'])->getJson('/v1/addresses/governorates')->assertOk()->json('data');

    expect($list)->toHaveCount(27)
        ->and($list[0])->toBe(['code' => 'C', 'name_ar' => 'القاهرة', 'name_en' => 'Cairo'])
        ->and(collect($list)->pluck('code')->unique())->toHaveCount(27);
});
