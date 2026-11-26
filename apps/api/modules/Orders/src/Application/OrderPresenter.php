<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Illuminate\Support\Collection;
use Modules\Orders\Domain\BoardStage;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\Orders\Infrastructure\Models\OrderItem;

/** The API shape of orders (also the order card in chat). Items are loaded in one query. */
final class OrderPresenter
{
    /**
     * @param  list<Order>  $orders
     * @return list<array<string, mixed>>
     */
    public function many(array $orders): array
    {
        $items = OrderItem::query()
            ->whereIn('order_id', array_map(fn (Order $o): string => $o->id, $orders))
            ->orderBy('position')
            ->get()
            ->groupBy('order_id');

        return array_map(fn (Order $o): array => $this->one($o, $items->get($o->id) ?? collect()), $orders);
    }

    /**
     * @param  Collection<int, OrderItem>|null  $items
     * @return array<string, mixed>
     */
    public function one(Order $o, ?Collection $items = null): array
    {
        $items ??= $o->items()->get();
        $money = fn (int $amount): array => ['amount' => $amount, 'currency' => $o->currency];
        $address = $o->deliveryAddress();

        return [
            'id' => $o->id,
            'number' => $o->number,
            'source' => $o->source->value,
            'status' => $o->status->value,
            'stage' => BoardStage::of($o->status, $o->needs_call_at !== null)->value,
            'owner_id' => $o->owner_id,
            'risk_score' => $o->risk_score,
            'needs_call' => $o->needs_call_at === null ? null : ['since' => $o->needs_call_at->toIso8601String(), 'reason' => $o->needs_call_reason],
            'next_action' => $o->nextAction()?->value,
            'contact_id' => $o->contact_id,
            'customer' => ['name' => $o->customer_name, 'phone' => $o->customer_phone],
            'payment_method' => $o->payment_method,
            'subtotal' => $money($o->subtotal),
            'shipping' => $money($o->shipping),
            'discount' => $money($o->discount),
            'total' => $money($o->total),
            'cod_amount' => $money($o->cod_amount),
            'items' => $items->map(fn (OrderItem $i): array => [
                'title' => $i->title,
                'variant' => $i->variant,
                'sku' => $i->sku,
                'quantity' => $i->quantity,
                'unit_price' => $money($i->unit_price),
                'total' => $money($i->total),
            ])->values()->all(),
            'address' => [...$address->toArray(), 'line' => $address->line(), 'needs_fix' => $o->address_needs_fix, 'fixed_at' => $o->address_fixed_at?->toIso8601String()],
            'delivery' => ['courier' => $o->courier, 'tracking_number' => $o->tracking_number, 'tracking_url' => $o->tracking_url],
            'note' => $o->note,
            'placed_at' => $o->placed_at->toIso8601String(),
            'confirmed_at' => $o->confirmed_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'shipped_at' => $o->shipped_at?->toIso8601String(),
            'delivered_at' => $o->delivered_at?->toIso8601String(),
        ];
    }

    /**
     * The order card in chat (#105): a customer's latest orders.
     *
     * @return list<array<string, mixed>>
     */
    public function forContact(string $contactId, int $limit = 20): array
    {
        /** @var list<Order> $orders */
        $orders = array_values(Order::query()->where('contact_id', $contactId)->orderByDesc('placed_at')->limit($limit)->get()->all());

        return $this->many($orders);
    }
}
