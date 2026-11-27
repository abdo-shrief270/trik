<?php

declare(strict_types=1);

namespace Modules\Waitlist\Infrastructure;

use Illuminate\Support\Facades\Notification;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;
use Modules\Waitlist\Infrastructure\Notifications\WaitlistConfirmation;

final class WaitlistMailer
{
    /** Queued, and only after the sign-up transaction commits (a slow mail server never fails the request). */
    public function sendConfirmation(WaitlistEntry $entry): void
    {
        Notification::route('mail', $entry->email)->notify(new WaitlistConfirmation($entry->id));
    }
}
