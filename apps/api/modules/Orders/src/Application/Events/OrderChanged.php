<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Events;

use Modules\Orders\Domain\OrderStatus;

/**
 * An order was created or changed (published after commit). The confirmation engine (#106) and the
 * store connectors (status write-back) listen to it.
 */
final readonly class OrderChanged
{
    public function __construct(
        public string $workspaceId,
        public string $orderId,
        public string $change, // created | updated | status_changed | address_fixed
        public ?OrderStatus $from = null,
        public ?OrderStatus $to = null,
        public ?string $actorId = null,
        // where the change came from: a store ("shopify"…) when RecordOrder applied the store's update,
        // null when it was made in Trik (an agent, the confirmation engine). Write-back listens to null only.
        public ?string $origin = null,
    ) {}
}
