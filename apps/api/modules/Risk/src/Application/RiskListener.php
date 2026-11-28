<?php

declare(strict_types=1);

namespace Modules\Risk\Application;

use App\Http\Middleware\EnsureFeatureEnabled;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\Orders\Application\OrderHistory;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Domain\OrderStatus;
use Modules\Risk\Application\Jobs\ScoreOrders;

/**
 * When to score: a new order; an edit to one (address, flags, a call that went unanswered); and when an
 * order ends, the same customer's open orders (a refusal raises them, a delivery lowers them).
 * Only where the `risk` feature is on.
 */
final class RiskListener
{
    public function __construct(
        private readonly OrderReader $orders,
        private readonly OrderHistory $history,
    ) {}

    public function orderChanged(OrderChanged $event): void
    {
        if (! EnsureFeatureEnabled::enabled('risk', $event->workspaceId)) {
            return;
        }

        $ids = match (true) {
            in_array($event->change, ['created', 'updated', 'address_fixed'], true) => [$event->orderId],
            $event->change === 'status_changed' && in_array($event->to, [OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Cancelled], true) => $this->history->openOrderIds((string) $this->orders->find($event->orderId)?->customerPhone),
            default => [],
        };

        if ($ids !== []) {
            ScoreOrders::dispatch($event->workspaceId, $ids);
        }
    }
}
