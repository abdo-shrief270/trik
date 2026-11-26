<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Events;

use Illuminate\Support\Str;

/** packages/contracts/events/order.confirmed.v1 — goes through the outbox once it exists (#29). */
final readonly class OrderConfirmed
{
    public string $eventId;

    public string $occurredAt;

    /** @param 'whatsapp'|'call'|'sms'|'agent' $channel */
    public function __construct(
        public string $workspaceId,
        public string $orderId,
        public string $channel,
        public ?string $confirmedBy = null,
    ) {
        $this->eventId = (string) Str::uuid7();
        $this->occurredAt = now()->toIso8601String();
    }

    /** @return array<string, mixed> the contract payload */
    public function toContract(): array
    {
        return [
            'event_id' => $this->eventId,
            'schema' => 'order.confirmed.v1',
            'occurred_at' => $this->occurredAt,
            'workspace_id' => $this->workspaceId,
            'data' => ['order_id' => $this->orderId, 'channel' => $this->channel, 'confirmed_by' => $this->confirmedBy],
        ];
    }
}
