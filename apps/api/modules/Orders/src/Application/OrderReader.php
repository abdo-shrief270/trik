<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Modules\Orders\Infrastructure\Models\Order;

/** Read access to orders for other modules (active workspace only). */
final class OrderReader
{
    public function find(string $orderId): ?OrderSnapshot
    {
        $o = Order::query()->find($orderId);

        return $o === null ? null : new OrderSnapshot(
            $o->id, $o->contact_id, $o->number, $o->status, $o->payment_method, $o->customer_phone,
            $o->customer_name, $o->total, $o->currency, $o->address_needs_fix,
        );
    }
}
