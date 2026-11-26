<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use App\Support\Events\EventPublisher;
use App\Support\Http\Problem;
use Illuminate\Support\Facades\DB;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\Orders\Application\Events\OrderConfirmed;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;

/** What the team does to an order: confirm, cancel, fix the address, flag it, add a note. */
final class ManageOrder
{
    public function __construct(private readonly EventPublisher $events, private readonly OrderNotifier $notifier) {}

    /**
     * @param  'whatsapp'|'call'|'sms'|'agent'  $channel
     */
    public function moveTo(Order $order, OrderStatus $to, ?string $actorId, string $channel = 'agent', ?string $reason = null): Order
    {
        $from = $order->status;

        if ($from === $to) {
            return $order; // idempotent: confirming a confirmed order is a no-op
        }

        if (! $from->canMoveTo($to)) {
            throw Problem::conflict('invalid-transition', "An order that is {$from->value} cannot become {$to->value}.");
        }

        if ($to === OrderStatus::Confirmed && $order->address_needs_fix) {
            throw Problem::conflict('address-needs-fix', 'Fix the delivery address before confirming this order.');
        }

        DB::transaction(function () use ($order, $from, $to, $actorId, $channel, $reason): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== $from) {
                throw Problem::conflict('order-changed', 'The order changed meanwhile. Reload it and try again.');
            }

            $order->status = $to;
            if (! $to->isPendingConfirmation()) {
                $order->needs_call_at = null;
                $order->needs_call_reason = null;
            }
            $column = $to->timestampColumn();
            if ($column !== null) {
                $order->{$column} = now();
            }
            $order->save();

            OrderTimeline::add($order->id, 'status_changed', $actorId, array_filter([
                'from' => $from->value, 'to' => $to->value, 'channel' => $channel, 'reason' => $reason,
            ], fn (?string $v): bool => $v !== null));
        });

        $this->events->publish(new OrderChanged($order->workspace_id, $order->id, 'status_changed', $from, $to, $actorId));
        if ($to === OrderStatus::Confirmed) {
            $this->events->publish(new OrderConfirmed($order->workspace_id, $order->id, $channel, $actorId));
        }
        $this->notifier->changed($order);

        return $order;
    }

    /**
     * The same, for other modules that hold only the order id (the confirmation engine).
     *
     * @param  'whatsapp'|'call'|'sms'|'agent'  $channel
     */
    public function moveById(string $orderId, OrderStatus $to, ?string $actorId, string $channel, ?string $reason = null): void
    {
        $this->moveTo(Order::query()->findOrFail($orderId), $to, $actorId, $channel, $reason);
    }

    /** Confirmation needs a person (no reply, a change requested…): the order joins "needs a call". */
    public function markNeedsCall(string $orderId, string $reason): void
    {
        $order = Order::query()->findOrFail($orderId);

        if ($order->status->isPendingConfirmation() && $order->needs_call_at === null) {
            $this->save($order, ['needs_call_at' => now(), 'needs_call_reason' => $reason], 'needs_call', null, ['reason' => $reason]);
        }
    }

    /** Back to waiting for the customer (e.g. the team sent another reminder). */
    public function clearNeedsCall(string $orderId): void
    {
        $order = Order::query()->findOrFail($orderId);

        if ($order->needs_call_at !== null) {
            $this->save($order, ['needs_call_at' => null, 'needs_call_reason' => null], 'needs_call_cleared', null);
        }
    }

    /** Who is responsible for the order (null = nobody). The caller checks the person may see orders. */
    public function assignOwner(Order $order, ?string $ownerId, ?string $actorId): Order
    {
        return $this->save($order, ['owner_id' => $ownerId], 'owner_changed', $actorId, ['owner_id' => $ownerId]);
    }

    /** Puts the agent's structured address on the order (#344); the store's original stays in `raw`. */
    public function fixAddress(Order $order, Address $address, ?string $actorId): Order
    {
        $before = $order->deliveryAddress();
        $merged = new Address(
            $address->raw ?? $before->raw,
            $address->governorate, $address->area, $address->street, $address->building, $address->floor, $address->apartment, $address->landmark,
        );

        if (! $merged->isDeliverable()) {
            throw Problem::unprocessable('address-incomplete', 'A delivery address needs at least the governorate, the area and the street.');
        }

        return $this->save($order, [
            'address' => $merged->toArray(),
            'address_needs_fix' => false,
            'address_fixed_at' => now(),
        ], 'address_fixed', $actorId, ['address' => $merged->toArray()]);
    }

    public function flagAddress(Order $order, bool $needsFix, ?string $actorId): Order
    {
        return $this->save($order, ['address_needs_fix' => $needsFix], $needsFix ? 'address_flagged' : 'address_unflagged', $actorId);
    }

    public function note(Order $order, ?string $note, ?string $actorId): Order
    {
        return $this->save($order, ['note' => $note], 'note_changed', $actorId);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>|null  $data
     */
    private function save(Order $order, array $changes, string $event, ?string $actorId, ?array $data = null): Order
    {
        $order->fill($changes);
        if (! $order->isDirty()) {
            return $order;
        }

        DB::transaction(function () use ($order, $event, $actorId, $data): void {
            $order->save();
            OrderTimeline::add($order->id, $event, $actorId, $data);
        });

        $this->events->publish(new OrderChanged($order->workspace_id, $order->id, $event === 'address_fixed' ? 'address_fixed' : 'updated', actorId: $actorId));
        $this->notifier->changed($order);

        return $order;
    }
}
