<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Events;

use Modules\WhatsApp\Domain\MessageStatus;

final readonly class MessageStatusChanged
{
    public function __construct(
        public string $workspaceId,
        public string $numberId,
        public string $messageId,
        public string $providerMessageId,
        public MessageStatus $status,
    ) {}
}
