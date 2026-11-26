<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Events;

/**
 * Status changes of a broadcast's sends, aggregated per batch: one event per broadcast per
 * batch instead of one per message, so campaign volume never floods the event bus.
 */
final readonly class BroadcastProgressed
{
    /** @param array<string, int> $changes new status => number of sends that moved to it */
    public function __construct(
        public string $workspaceId,
        public string $broadcastId,
        public array $changes,
    ) {}
}
