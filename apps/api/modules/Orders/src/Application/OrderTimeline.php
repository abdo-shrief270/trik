<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Modules\Orders\Infrastructure\Models\OrderEvent;

final class OrderTimeline
{
    /** @param array<string, mixed>|null $data */
    public static function add(string $orderId, string $type, ?string $actorId = null, ?array $data = null): void
    {
        OrderEvent::query()->create(['order_id' => $orderId, 'type' => $type, 'actor_id' => $actorId, 'data' => $data]);
    }
}
