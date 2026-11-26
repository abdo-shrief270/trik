<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Events;

use DateTimeInterface;
use Modules\WhatsApp\Domain\Direction;

/** One conversation message as it was written to the log (either direction). */
final readonly class LoggedMessage
{
    public function __construct(
        public string $messageId,
        public string $numberId,
        public Direction $direction,
        public string $contactPhone,
        public string $type,
        public DateTimeInterface $at,
        public ?string $preview = null,
        public ?string $contactName = null,
        /** The customer came from a Click-to-WhatsApp ad or a Facebook Page button (Meta's free entry point). */
        public bool $fromAd = false,
        public ?string $sentBy = null,
    ) {}
}
