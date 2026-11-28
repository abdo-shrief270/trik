<?php

declare(strict_types=1);

namespace Modules\Commerce\Application;

use Modules\Commerce\Application\Jobs\WriteBackShopifyOrder;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Domain\OrderStatus;

/**
 * Queues a write-back when something happens to a store order *in Trik* (an agent, the confirmation
 * engine): a final confirmation or cancellation, or a fixed address. Changes that came from the store
 * itself (OrderChanged::origin set) are never written back, so Shopify's own webhooks can't loop.
 */
final class WriteBackListener
{
    public function __construct(private readonly OrderReader $orders) {}

    public function orderChanged(OrderChanged $event): void
    {
        if ($event->origin !== null) {
            return;
        }

        $what = match (true) {
            $event->change === 'status_changed' && in_array($event->to, [OrderStatus::Confirmed, OrderStatus::Cancelled], true) => 'status',
            $event->change === 'address_fixed' => 'address',
            default => null,
        };
        if ($what === null || $this->orders->find($event->orderId)?->source !== 'shopify') {
            return;
        }

        WriteBackShopifyOrder::dispatch($event->workspaceId, $event->orderId, $what);
    }
}
