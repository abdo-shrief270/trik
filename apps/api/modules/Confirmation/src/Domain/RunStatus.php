<?php

declare(strict_types=1);

namespace Modules\Confirmation\Domain;

/**
 * pending → sent (→ sent again for each reminder) → resolved by a reply, by the team, or escalated.
 * `pending` and `sent` are the only states the scheduler acts on.
 */
enum RunStatus: string
{
    case Pending = 'pending';      // waiting to send the first message (within seconds)
    case Sent = 'sent';            // waiting for the customer; next_action_at = reminder or escalation
    case Resolved = 'resolved';    // the customer answered, or the team settled the order
    case Escalated = 'escalated';  // no answer: a person needs to call ("needs a call" on the board)
    case Failed = 'failed';        // could not send (no number, the message failed)
    case Skipped = 'skipped';      // not a COD order awaiting confirmation, or the engine is off

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Sent;
    }
}
