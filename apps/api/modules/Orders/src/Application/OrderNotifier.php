<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use App\Support\Realtime\Realtime;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Infrastructure\Models\Order;

/** Tells open inboxes that an order card changed (after commit). */
final class OrderNotifier
{
    public function __construct(private readonly Realtime $realtime) {}

    public function changed(Order $order): void
    {
        $message = [[
            'channel' => 'inbox:'.$order->workspace_id,
            'data' => [
                'type' => 'order.updated',
                'id' => $order->id,
                'contact_id' => $order->contact_id,
                'status' => $order->status->value,
                'next_action' => $order->nextAction()?->value,
            ],
        ]];

        DB::afterCommit(fn () => $this->realtime->publish($message));
    }
}
