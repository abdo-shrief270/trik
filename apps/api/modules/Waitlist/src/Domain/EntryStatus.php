<?php

declare(strict_types=1);

namespace Modules\Waitlist\Domain;

/** pending → confirmed (link opened) · any → unsubscribed · unsubscribed → pending (signs up again). */
enum EntryStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
