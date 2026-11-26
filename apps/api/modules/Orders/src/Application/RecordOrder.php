<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Contacts\Application\ContactDirectory;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\Orders\Infrastructure\Models\OrderItem;
use Modules\WhatsApp\Domain\PhoneNumber;

/**
 * The one way an order enters Trik, from any source. Idempotent: the same (source, external id)
 * again updates the order instead of duplicating it, so store webhooks can be redelivered freely.
 *
 * On an update from a store: items, totals, courier and tracking follow the store; the status only
 * moves forward; an address an agent already fixed is kept (the agent's version wins until written back).
 */
final class RecordOrder
{
    public function __construct(
        private readonly ContactDirectory $contacts,
        private readonly TenantContext $tenant,
        private readonly EventPublisher $events,
        private readonly OrderNotifier $notifier,
    ) {}

    public function handle(OrderInput $input, ?string $actorId = null): Order
    {
        $phone = PhoneNumber::fromString($input->customerPhone, (string) config('whatsapp.default_country_code', '20'))->digits;

        [$order, $change, $from] = DB::transaction(function () use ($input, $phone, $actorId): array {
            $contactId = $this->contacts->resolve([$phone => $input->customerName])[$phone];
            $externalId = $input->externalId ?? $this->nextChatNumber();

            /** @var Order|null $existing */
            $existing = Order::query()->where('source', $input->source)->where('external_id', $externalId)->lockForUpdate()->first();

            if ($existing === null) {
                $order = Order::query()->create([
                    'contact_id' => $contactId,
                    'source' => $input->source,
                    'external_id' => $externalId,
                    'number' => $input->number ?? $externalId,
                    'status' => $input->status ?? OrderStatus::New,
                    'customer_phone' => $phone,
                    'customer_name' => $input->customerName,
                    'address' => $input->address->toArray(),
                    'placed_at' => $input->placedAt,
                    'note' => $input->note,
                    ...$this->money($input),
                    ...$this->delivery($input),
                ]);
                $this->replaceItems($order, $input);
                OrderTimeline::add($order->id, 'created', $actorId, ['source' => $input->source->value, 'total' => $order->total]);

                return [$order, 'created', null];
            }

            $from = $existing->status;
            $existing->fill([...$this->money($input), ...$this->delivery($input)]);
            // compared as values: jsonb hands keys back in its own order, which is not a change
            if ($existing->address_fixed_at === null && $existing->deliveryAddress()->toArray() != $input->address->toArray()) {
                $existing->address = $input->address->toArray();
            }
            if ($input->status !== null && $existing->status->canMoveTo($input->status)) {
                $existing->status = $input->status;
                $column = $input->status->timestampColumn();
                if ($column !== null) {
                    $existing->{$column} = now();
                }
            }

            if (! $existing->isDirty()) {
                return [$existing, null, null];
            }

            $existing->save();
            $this->replaceItems($existing, $input);
            $statusChanged = $existing->status !== $from;
            OrderTimeline::add($existing->id, $statusChanged ? 'status_changed' : 'updated', $actorId, $statusChanged
                ? ['from' => $from->value, 'to' => $existing->status->value, 'by' => $input->source->value]
                : ['by' => $input->source->value]);

            return [$existing, $statusChanged ? 'status_changed' : 'updated', $statusChanged ? $from : null];
        });

        if ($change !== null) {
            $this->events->publish(new OrderChanged($order->workspace_id, $order->id, $change, $from, $from !== null ? $order->status : null, $actorId));
            $this->notifier->changed($order);
        }

        return $order;
    }

    /** Chat orders are numbered per workspace: 1001, 1002, … (serialised per workspace). */
    private function nextChatNumber(): string
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['orders-number:'.$this->tenant->require()]);
        $last = Order::query()->where('source', OrderSource::Chat)->max(DB::raw('external_id::bigint'));

        return (string) (max(1000, is_numeric($last) ? (int) $last : 1000) + 1);
    }

    private function replaceItems(Order $order, OrderInput $input): void
    {
        OrderItem::query()->where('order_id', $order->id)->delete();
        foreach ($input->items as $i => $item) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'external_id' => $item->externalId,
                'sku' => $item->sku,
                'title' => $item->title,
                'variant' => $item->variant,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice,
                'total' => $item->total(),
                'position' => $i,
            ]);
        }
    }

    /** @return array<string, int|string> */
    private function money(OrderInput $input): array
    {
        return [
            'payment_method' => $input->paymentMethod,
            'currency' => $input->currency,
            'subtotal' => $input->subtotal(),
            'shipping' => $input->shipping,
            'discount' => $input->discount,
            'total' => $input->grandTotal(),
            'cod_amount' => $input->codAmount(),
        ];
    }

    /** @return array<string, string|null> */
    private function delivery(OrderInput $input): array
    {
        return array_filter([
            'courier' => $input->courier,
            'tracking_number' => $input->trackingNumber,
            'tracking_url' => $input->trackingUrl,
        ], fn (?string $v): bool => $v !== null);
    }
}
