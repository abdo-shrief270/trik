<?php

declare(strict_types=1);

namespace Modules\Waitlist\Application;

use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;

/** What the signed links in the emails do. Both are idempotent. */
final class ManageEntry
{
    /**
     * Confirms a pending entry. A confirmed one stays confirmed; an unsubscribed one stays out (an old link
     * opened after leaving the list must not put the person back on it).
     */
    public function confirm(WaitlistEntry $entry): EntryStatus
    {
        if ($entry->status === EntryStatus::Pending) {
            $entry->forceFill(['status' => EntryStatus::Confirmed, 'confirmed_at' => now()])->save();
        }

        return $entry->status;
    }

    public function unsubscribe(WaitlistEntry $entry): void
    {
        if ($entry->status !== EntryStatus::Unsubscribed) {
            $entry->forceFill(['status' => EntryStatus::Unsubscribed, 'unsubscribed_at' => now()])->save();
        }
    }
}
