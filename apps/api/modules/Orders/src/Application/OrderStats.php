<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Counts of cash-on-delivery orders by what happened to them, straight from their statuses (which follow the
 * store), for orders placed in a period. Inside the active workspace only.
 *
 *  - confirmed: reached confirmation or beyond (confirmed, ready to ship, shipped, delivered, refused);
 *  - shipped:   shipped, delivered or refused; delivered / refused: the outcome at the door;
 *  - open:      still waiting for the customer to confirm.
 */
final class OrderStats
{
    private const SELECT = <<<'SQL'
        count(*) as placed,
        count(*) filter (where status in ('new', 'confirming', 'awaiting_deposit')) as open,
        count(*) filter (where status in ('confirmed', 'ready_to_ship', 'shipped', 'delivered', 'returned')) as confirmed,
        count(*) filter (where status = 'cancelled') as cancelled,
        count(*) filter (where status in ('shipped', 'delivered', 'returned')) as shipped,
        count(*) filter (where status = 'delivered') as delivered,
        count(*) filter (where status = 'returned') as refused,
        count(*) filter (where confirmed_at is not null and confirmed_at >= placed_at) as timed,
        percentile_cont(0.5) within group (order by extract(epoch from confirmed_at - placed_at) / 60)
            filter (where confirmed_at is not null and confirmed_at >= placed_at) as median_confirm_minutes
        SQL;

    /** @return array<string, int|float|null> */
    public function period(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $row = DB::selectOne('select '.self::SELECT." from orders_orders where payment_method = 'cod' and placed_at >= ? and placed_at < ?",
            [$from->format(DATE_ATOM), $to->format(DATE_ATOM)]);

        return self::numbers($row === null ? [] : get_object_vars($row));
    }

    /**
     * The same counts per local day (or ISO week), oldest first; days without orders are left out.
     *
     * @param  'day'|'week'  $by
     * @return list<array<string, int|float|string|null>>
     */
    public function series(DateTimeInterface $from, DateTimeInterface $to, string $timezone, string $by = 'day'): array
    {
        $bucket = $by === 'week' ? "date_trunc('week', placed_at at time zone ?)::date" : '(placed_at at time zone ?)::date';
        $rows = DB::select('select '.$bucket.' as bucket, '.self::SELECT
            ." from orders_orders where payment_method = 'cod' and placed_at >= ? and placed_at < ? group by 1 order by 1",
            [$timezone, $from->format(DATE_ATOM), $to->format(DATE_ATOM)]);

        return array_values(array_map(function (object $row): array {
            $r = get_object_vars($row);

            return ['date' => (string) $r['bucket'], ...self::numbers($r)];
        }, $rows));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, int|float|null>
     */
    private static function numbers(array $r): array
    {
        $out = [];
        foreach (['placed', 'open', 'confirmed', 'cancelled', 'shipped', 'delivered', 'refused', 'timed'] as $k) {
            $out[$k] = (int) ($r[$k] ?? 0);
        }
        $out['median_confirm_minutes'] = isset($r['median_confirm_minutes']) ? round((float) $r['median_confirm_minutes'], 1) : null;

        return $out;
    }
}
