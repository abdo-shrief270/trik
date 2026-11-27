<?php

declare(strict_types=1);

use App\Support\Privacy\DataSubject;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\OrdersPersonalData;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Infrastructure\Models\Order;

/** A Shopify order with a timeline entry carrying a free-text reason. */
function orderWithReason(string $ws, string $externalId): Order
{
    return inWs($ws, function () use ($ws, $externalId): Order {
        $order = app(RecordOrder::class)->handle(new OrderInput(OrderSource::Shopify, $externalId, '#'.$externalId, '01012345678', 'Mona',
            new Address(raw: '12 التحرير'), [new OrderItemInput('فستان', 1, 90000)], now()->subDays(3)));
        DB::table('orders_events')->insert([
            'id' => (string) Str::uuid7(), 'workspace_id' => $ws, 'order_id' => $order->id, 'type' => 'status_changed',
            'data' => json_encode(['from' => 'new', 'to' => 'cancelled', 'reason' => 'منى قالت كذا']), 'created_at' => now(),
        ]);

        return $order;
    });
}

beforeEach(function (): void {
    $this->a = ownerWithWorkspace('A')['workspace'];
    $this->b = ownerWithWorkspace('B')['workspace'];
});

it("redacts timeline reasons only in the caller's workspace, even when handed another workspace's ids", function (): void {
    $mine = orderWithReason($this->a, 'S-1');
    $theirs = orderWithReason($this->b, 'S-2');

    $changed = inWs($this->a, fn () => DB::selectOne('select orders_redact_event_reasons(?::uuid[]) as n', ['{'.$mine->id.','.$theirs->id.'}'])->n);

    expect($changed)->toBe(1)
        ->and(inWs($this->a, fn () => DB::table('orders_events')->where('order_id', $mine->id)->whereRaw("jsonb_exists(data, 'reason')")->count()))->toBe(0)
        ->and(inWs($this->b, fn () => DB::table('orders_events')->where('order_id', $theirs->id)->whereRaw("jsonb_exists(data, 'reason')")->count()))->toBe(1)
        // the rest of the entry stays: it is history, not personal data
        ->and(inWs($this->a, fn () => json_decode((string) DB::table('orders_events')->where('order_id', $mine->id)->where('type', 'status_changed')->value('data'), true)))
        ->toEqual(['from' => 'new', 'to' => 'cancelled']); // jsonb orders keys its own way: compare as values
});

it('refuses to run outside a workspace', function (): void {
    expect(fn () => DB::transaction(fn () => DB::select('select orders_redact_event_reasons(?::uuid[])', ['{}'])))->toThrow(QueryException::class);
});

it("redacts through the source without touching another workspace's customer", function (): void {
    $mine = orderWithReason($this->a, 'S-1');
    $theirs = orderWithReason($this->b, 'S-2');

    $count = inWs($this->a, fn () => app(OrdersPersonalData::class)->redact(new DataSubject(['201012345678'], 'shopify')));

    expect($count)->toBe(1)
        ->and(inWs($this->a, fn () => Order::query()->find($mine->id)->customer_phone))->toBe('')
        ->and(inWs($this->b, fn () => Order::query()->find($theirs->id)->customer_phone))->toBe('201012345678');
});
