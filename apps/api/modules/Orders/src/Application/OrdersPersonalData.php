<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use App\Support\Privacy\DataSubject;
use App\Support\Privacy\PersonalDataSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\Orders\Infrastructure\Models\OrderItem;

/**
 * Orders in privacy requests. A request from a store covers that store's orders only (its source and
 * order ids, or the customer's phone within that source); chat orders are the seller's own records.
 *
 * Redaction anonymises the customer on the order (name, phone, address, note, and free-text reasons
 * in the timeline) and keeps what isn't personal: items, amounts, statuses and dates, which the seller
 * still needs for their books (Shopify keeps redacted orders the same way).
 */
final class OrdersPersonalData implements PersonalDataSource
{
    public function name(): string
    {
        return 'orders';
    }

    public function expand(DataSubject $subject): DataSubject
    {
        if ($subject->orderIds === []) {
            return $subject;
        }

        $phones = [];
        foreach (array_chunk($subject->orderIds, 1000) as $ids) {
            $q = Order::query()->whereIn('external_id', $ids);
            if ($subject->orderSource !== null) {
                $q->where('source', $subject->orderSource);
            }
            array_push($phones, ...$q->where('customer_phone', '!=', '')->distinct()->pluck('customer_phone')->map(fn (mixed $p): string => (string) $p)->all());
        }

        return $subject->withPhones(array_values(array_unique($phones)));
    }

    public function export(DataSubject $subject): array
    {
        $out = [];
        $this->matching($subject)->orderBy('placed_at')->chunkById(500, function ($orders) use (&$out): void {
            $items = OrderItem::query()->whereIn('order_id', $orders->pluck('id'))->orderBy('position')->get()->groupBy('order_id');

            foreach ($orders as $o) {
                /** @var Order $o */
                $out[] = [
                    'number' => $o->number,
                    'source' => $o->source->value,
                    'external_id' => $o->external_id,
                    'placed_at' => $o->placed_at->toIso8601String(),
                    'status' => $o->status->value,
                    'customer_name' => $o->customer_name,
                    'customer_phone' => $o->customer_phone,
                    'address' => $o->address,
                    'note' => $o->note,
                    'payment_method' => $o->payment_method,
                    'currency' => $o->currency,
                    'total' => $o->total,
                    'courier' => $o->courier,
                    'tracking_number' => $o->tracking_number,
                    'items' => ($items[$o->id] ?? collect())->map(fn (OrderItem $i): array => [
                        'title' => $i->title, 'variant' => $i->variant, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
                    ])->values()->all(),
                ];
            }
        });

        return $out;
    }

    public function redact(DataSubject $subject): int
    {
        $count = 0;
        $this->matching($subject)->select('id')->chunkById(500, function ($orders) use (&$count): void {
            $ids = $orders->pluck('id')->all();

            DB::transaction(function () use ($ids): void {
                Order::query()->whereIn('id', $ids)->update([
                    'customer_name' => null,
                    'customer_phone' => '',
                    'address' => '{}',
                    'note' => null,
                    'updated_at' => now(),
                ]);
                // cancel reasons and the like are typed freely by people: they may name the customer. The timeline
                // is append-only for the app role; this narrow function only drops `reason` in this workspace.
                DB::select('select orders_redact_event_reasons(?::uuid[])', ['{'.implode(',', $ids).'}']);
            });

            $count += count($ids);
        });

        return $count;
    }

    /** @return Builder<Order> */
    private function matching(DataSubject $subject): Builder
    {
        $q = Order::query();

        if ($subject->orderIds === [] && $subject->phones === []) {
            return $q->whereRaw('false');
        }
        if ($subject->orderSource !== null) {
            $q->where('source', $subject->orderSource);
        }

        return $q->where(function (Builder $w) use ($subject): void {
            if ($subject->orderIds !== []) {
                $w->whereIn('external_id', $subject->orderIds);
            }
            if ($subject->phones !== []) {
                $w->orWhereIn('customer_phone', $subject->phones);
            }
        });
    }
}
