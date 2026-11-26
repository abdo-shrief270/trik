<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Events;

use Modules\WhatsApp\Domain\Inbound\InboundMessage;

/** A customer sent a message to a connected number. Emitted once per message (duplicates are dropped). */
final readonly class MessageReceived
{
    public function __construct(
        public string $workspaceId,
        public string $numberId,
        public string $messageId,
        public InboundMessage $message,
    ) {}
}
