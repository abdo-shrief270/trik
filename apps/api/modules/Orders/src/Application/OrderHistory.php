<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;

/**
 * Order history for other modules (refusal risk), always inside the active workspace: row-level security
 * keeps every number to the seller's own data.
 */
final class OrderHistory
{
    /** The customer's other orders: delivered, refused (returned at delivery), cancelled, and unreachable for confirmation. */
    public function customer(string $phone, string $exceptOrderId): CustomerHistory
    {
        if ($phone === '') {
            return new CustomerHistory;
        }

        $row = DB::selectOne(<<<'SQL'
            select count(*) as orders,
                   count(*) filter (where o.status = 'delivered') as delivered,
                   count(*) filter (where o.status = 'returned') as refused,
                   count(*) filter (where o.status = 'cancelled') as cancelled,
                   count(*) filter (where exists (
                       select 1 from orders_events e
                        where e.order_id = o.id and e.type = 'needs_call' and e.data->>'reason' = 'no_reply'
                   )) as unreachable
              from orders_orders o
             where o.customer_phone = ? and o.id <> ?
            SQL, [$phone, $exceptOrderId]);
        $r = $row === null ? [] : get_object_vars($row);

        return new CustomerHistory((int) ($r['orders'] ?? 0), (int) ($r['delivered'] ?? 0), (int) ($r['refused'] ?? 0), (int) ($r['cancelled'] ?? 0), (int) ($r['unreachable'] ?? 0));
    }

    /**
     * The seller's usual cash-on-delivery amount (median of the last 500 COD orders, minor units) and how
     * many orders it rests on. Cached ten minutes per workspace.
     *
     * @return array{median: int|null, sample: int}
     */
    public function codMedian(string $workspaceId): array
    {
        return Cache::remember("orders:cod-median:{$workspaceId}", 600, function (): array {
            $row = DB::selectOne(<<<'SQL'
                select percentile_cont(0.5) within group (order by cod_amount) as median, count(*) as sample
                  from (select cod_amount from orders_orders where payment_method = 'cod' and cod_amount > 0
                        order by placed_at desc limit 500) recent
                SQL);
            $r = $row === null ? [] : get_object_vars($row);

            return ['median' => isset($r['median']) ? (int) round((float) $r['median']) : null, 'sample' => (int) ($r['sample'] ?? 0)];
        });
    }

    /**
     * The customer's orders still worth scoring (not delivered, refused or cancelled yet).
     *
     * @return list<string>
     */
    public function openOrderIds(string $phone): array
    {
        if ($phone === '') {
            return [];
        }

        return array_values(Order::query()->where('customer_phone', $phone)
            ->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Cancelled])
            ->limit(50)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    /** Sets the order's refusal risk score (the board filters on it). Quiet: not an edit of the order. */
    public function recordRisk(string $orderId, ?int $score): void
    {
        Order::query()->whereKey($orderId)->toBase()->update(['risk_score' => $score === null ? null : max(0, min(100, $score))]);
    }
}
