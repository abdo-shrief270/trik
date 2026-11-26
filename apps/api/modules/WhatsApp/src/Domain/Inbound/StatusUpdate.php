<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Inbound;

use Modules\WhatsApp\Domain\MessageStatus;

final readonly class StatusUpdate implements InboundEvent
{
    /** @param list<array<string, mixed>> $errors */
    public function __construct(
        public string $businessPhoneNumberId,
        public string $messageId,
        public MessageStatus $status,
        public string $recipient,
        public int $timestamp,
        public array $errors = [],
        public ?string $pricingCategory = null,
    ) {}

    public function phoneNumberId(): string
    {
        return $this->businessPhoneNumberId;
    }
}
