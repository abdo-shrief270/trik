<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Received = 'received';

    /** Status webhooks can arrive out of order; a message never moves backwards. */
    public function canMoveTo(self $next): bool
    {
        if ($this === self::Failed || $this === self::Received || $next === self::Queued || $next === self::Received) {
            return false;
        }

        if ($next === self::Failed) {
            return $this !== self::Read;
        }

        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed, self::Received => 9,
        };
    }
}
