<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;

/** Read access to orders for other modules (active workspace only). */
final class OrderReader
{
    public function find(string $orderId): ?OrderSnapshot
    {
        $o = Order::query()->find($orderId);

        return $o === null ? null : new OrderSnapshot(
            $o->id, $o->contact_id, $o->number, $o->status, $o->payment_method, $o->customer_phone,
            $o->customer_name, $o->total, $o->currency, $o->address_needs_fix, $o->placed_at,
            $o->source->value, $o->external_id, $o->deliveryAddress()->toArray(), $o->address_fixed_at !== null,
        );
    }

    /**
     * The workspace's most recent orders that aren't finished (delivered, refused or cancelled).
     *
     * @return list<string>
     */
    public function openOrderIds(int $limit): array
    {
        return array_values(Order::query()
            ->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Cancelled])
            ->orderByDesc('placed_at')->limit($limit)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }
}
