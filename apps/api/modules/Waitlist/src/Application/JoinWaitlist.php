<?php

declare(strict_types=1);

namespace Modules\Waitlist\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;
use Modules\Waitlist\Infrastructure\WaitlistMailer;

/**
 * Double opt-in sign-up. The caller always gets the same answer, so this never reveals who is on the list:
 *  - new email           → pending entry + confirmation link;
 *  - pending             → the link again, at most every RESEND_COOLDOWN_MINUTES and MAX_SENDS times
 *                          (details are not overwritten: anyone can type someone else's email);
 *  - confirmed           → nothing changes, no email;
 *  - unsubscribed        → back to pending with the new details and consent, and a new link.
 */
final class JoinWaitlist
{
    public const RESEND_COOLDOWN_MINUTES = 10;

    /** Confirmation emails per opt-in cycle; stops the form being used to flood someone's inbox. */
    public const MAX_SENDS = 5;

    public function __construct(private readonly WaitlistMailer $mailer) {}

    public function handle(SignupInput $in): void
    {
        try {
            DB::transaction(fn () => $this->apply($in));
        } catch (UniqueConstraintViolationException) {
            // The same email arrived twice at once: the other request created the entry and sends the link.
        }
    }

    private function apply(SignupInput $in): void
    {
        $entry = WaitlistEntry::query()->where('email', $in->email)->lockForUpdate()->first();

        if ($entry === null) {
            $entry = new WaitlistEntry;
            $entry->forceFill(['email' => $in->email, ...$this->details($in), 'status' => EntryStatus::Pending, 'confirmations_sent' => 0])->save();
            $this->send($entry);

            return;
        }

        match ($entry->status) {
            EntryStatus::Confirmed => null,
            EntryStatus::Pending => $this->resend($entry),
            EntryStatus::Unsubscribed => $this->resubscribe($entry, $in),
        };
    }

    private function resend(WaitlistEntry $entry): void
    {
        $cooledDown = $entry->confirmation_sent_at === null
            || $entry->confirmation_sent_at->lt(now()->subMinutes(self::RESEND_COOLDOWN_MINUTES));

        if ($cooledDown && $entry->confirmations_sent < self::MAX_SENDS) {
            $this->send($entry);
        }
    }

    private function resubscribe(WaitlistEntry $entry, SignupInput $in): void
    {
        // Only the owner of the address can unsubscribe (signed link), so a new opt-in cycle starts here.
        $entry->forceFill([...$this->details($in), 'status' => EntryStatus::Pending, 'unsubscribed_at' => null, 'confirmed_at' => null, 'confirmations_sent' => 0])->save();
        $this->send($entry);
    }

    private function send(WaitlistEntry $entry): void
    {
        $entry->forceFill(['confirmation_sent_at' => now(), 'confirmations_sent' => $entry->confirmations_sent + 1])->save();
        $this->mailer->sendConfirmation($entry);
    }

    /** @return array<string, mixed> */
    private function details(SignupInput $in): array
    {
        return [
            'name' => $in->name,
            'store_name' => $in->storeName,
            'phone' => $in->phone,
            'platform' => $in->platform,
            'monthly_orders' => $in->monthlyOrders,
            'locale' => $in->locale,
            'consent_at' => now(),
            'consent_ip' => $in->ip,
        ];
    }
}
