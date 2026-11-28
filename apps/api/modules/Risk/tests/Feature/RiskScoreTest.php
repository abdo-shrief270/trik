<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Addresses\Domain\Address;
use Modules\Addresses\Domain\Governorate;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\Workspaces\Domain\WorkspaceRole;

/** A store order placed at 14:00 Cairo time; $status set as the store reports it. */
function riskOrder(string $ws, string $id, string $phone = '01012345678', ?OrderStatus $status = null, int $price = 90000, ?Address $address = null): Order
{
    return inWs($ws, fn () => app(RecordOrder::class)->handle(new OrderInput(OrderSource::Shopify, $id, '#'.$id, $phone, 'Mona',
        $address ?? new Address(raw: '12 شارع التحرير، الدقي', governorate: Governorate::Giza, area: 'الدقي', street: 'التحرير'),
        [new OrderItemInput('فستان', 1, $price)], now()->setTimezone('Africa/Cairo')->setTime(14, 0)->setTimezone('UTC'), status: $status)));
}

function riskOf(string $ws, Order $order): ?int
{
    return inWs($ws, fn () => Order::query()->find($order->id)->risk_score);
}

beforeEach(function (): void {
    config(['trik.features.risk' => 'all']);
    Queue::fake([SendOutgoingMessage::class]);
    $this->owner = ownerWithWorkspace('Laila Fashion');
    $this->ws = $this->owner['workspace'];
});

it('scores a new order, explains it in Arabic and English, and puts the score on the board', function (): void {
    $order = riskOrder($this->ws, 'A-1');

    expect(riskOf($this->ws, $order))->toBe(35); // a first order: baseline 20 + 15

    $risk = inboxAs($this->owner['headers'])->getJson("/v1/orders/{$order->id}/risk")->assertOk();
    expect($risk->json('data.score'))->toBe(35)
        ->and($risk->json('data.flagged'))->toBeFalse()
        ->and($risk->json('data.threshold'))->toBe(70)
        ->and($risk->json('data.top.0'))->toMatchArray(['code' => 'first_order', 'points' => 15])
        ->and($risk->json('data.top.0.text'))->toBe(['ar' => 'أول طلب للعميل ده عندك', 'en' => "This customer's first order with you"])
        ->and(array_sum(array_column($risk->json('data.reasons'), 'points')))->toBe(35);

    inboxAs($this->owner['headers'])->getJson('/v1/orders/board?stage=new&min_risk=30')->assertOk()->assertJsonCount(1, 'data');
    inboxAs($this->owner['headers'])->getJson('/v1/orders/board?stage=new&min_risk=40')->assertOk()->assertJsonCount(0, 'data');
});

it("raises a customer's open order when they refuse another, and flags it once on the timeline", function (): void {
    riskOrder($this->ws, 'B-0', status: OrderStatus::Shipped);
    riskOrder($this->ws, 'B-1', status: OrderStatus::Shipped);
    $open = riskOrder($this->ws, 'B-2');
    expect(riskOf($this->ws, $open))->toBe(20); // a known customer, nothing delivered or refused yet

    // the store reports both shipped orders came back refused
    riskOrder($this->ws, 'B-1', status: OrderStatus::Returned);
    riskOrder($this->ws, 'B-0', status: OrderStatus::Returned);

    expect(riskOf($this->ws, $open))->toBe(70);
    $flags = inWs($this->ws, fn () => DB::table('orders_events')->where('order_id', $open->id)->where('type', 'risk_flagged')->pluck('data')->all());
    expect($flags)->toHaveCount(1)
        ->and(json_decode((string) $flags[0], true))->toEqual(['score' => 70, 'threshold' => 70, 'reasons' => ['refused_before']]);

    // scored again later: still one flag
    inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$open->id}", ['note' => 'call first'])->assertOk();
    expect(inWs($this->ws, fn () => DB::table('orders_events')->where('order_id', $open->id)->where('type', 'risk_flagged')->count()))->toBe(1);
    expect(inboxAs($this->owner['headers'])->getJson("/v1/orders/{$open->id}/risk")->json('data.top.0.text.en'))->toBe('Refused 2 earlier order(s) at the door');
});

it("reads only the seller's own orders", function (): void {
    $other = ownerWithWorkspace('Another seller');
    // the same customer refused twice at another seller
    riskOrder($other['workspace'], 'X-1', status: OrderStatus::Returned);
    riskOrder($other['workspace'], 'X-2', status: OrderStatus::Returned);

    $mine = riskOrder($this->ws, 'M-1');

    expect(riskOf($this->ws, $mine))->toBe(35); // a first order here, nothing from the other seller
    inboxAs($other['headers'])->getJson("/v1/orders/{$mine->id}/risk")->assertNotFound();
});

it('flags incomplete addresses and unusually large orders', function (): void {
    $order = riskOrder($this->ws, 'C-1', price: 400000, address: new Address(raw: 'جنب الجامع'));

    $codes = array_column(inboxAs($this->owner['headers'])->getJson("/v1/orders/{$order->id}/risk")->json('data.reasons'), 'points', 'code');
    expect($codes)->toMatchArray(['first_order' => 15, 'high_value' => 15, 'address_incomplete' => 15])
        ->and(riskOf($this->ws, $order))->toBe(65);
});

it('does nothing where the feature is off', function (): void {
    config(['trik.features.risk' => 'none']);
    $order = riskOrder($this->ws, 'D-1');

    expect(riskOf($this->ws, $order))->toBeNull();
    inboxAs($this->owner['headers'])->getJson('/v1/risk/settings')->assertNotFound();
});

it('lets managers change the threshold and weights, rescoring open orders', function (): void {
    $order = riskOrder($this->ws, 'E-1');

    inboxAs($this->owner['headers'])->getJson('/v1/risk/settings')->assertOk()
        ->assertJsonPath('data.threshold', 70)->assertJsonPath('data.weights.first_order', 15);

    inboxAs($this->owner['headers'])->putJson('/v1/risk/settings', ['threshold' => 30, 'weights' => ['first_order' => 40]])->assertOk()
        ->assertJsonPath('data.threshold', 30)->assertJsonPath('data.weights.first_order', 40);

    expect(riskOf($this->ws, $order))->toBe(60)
        ->and(inboxAs($this->owner['headers'])->getJson("/v1/orders/{$order->id}/risk")->json('data.flagged'))->toBeTrue();

    inboxAs($this->owner['headers'])->putJson('/v1/risk/settings', ['weights' => ['made_up' => 5]])->assertUnprocessable();
    inboxAs($this->owner['headers'])->putJson('/v1/risk/settings', ['weights' => ['first_order' => 99]])->assertUnprocessable();
    inboxAs($this->owner['headers'])->putJson('/v1/risk/settings', ['threshold' => 0])->assertUnprocessable();

    $agent = teammate($this->ws, WorkspaceRole::Agent);
    inboxAs($agent['headers'])->putJson('/v1/risk/settings', ['threshold' => 99])->assertForbidden();
    inboxAs($agent['headers'])->getJson("/v1/orders/{$order->id}/risk")->assertOk(); // agents see the reasons on orders
});
