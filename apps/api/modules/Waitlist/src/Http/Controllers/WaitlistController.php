<?php

declare(strict_types=1);

namespace Modules\Waitlist\Http\Controllers;

use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Waitlist\Application\JoinWaitlist;
use Modules\Waitlist\Application\ManageEntry;
use Modules\Waitlist\Domain\EntryStatus;
use Modules\Waitlist\Http\Requests\JoinWaitlistRequest;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;
use Modules\Waitlist\Infrastructure\WaitlistLinks;

final class WaitlistController
{
    public function __construct(private readonly WaitlistLinks $links) {}

    /** Same answer for every email, known or not. */
    public function join(JoinWaitlistRequest $request, JoinWaitlist $join): JsonResponse
    {
        $join->handle($request->toInput());

        return response()->json(['message' => 'Check your email for a link to confirm your place.'], 202);
    }

    public function confirm(Request $request, string $entry, ManageEntry $manage): JsonResponse|RedirectResponse
    {
        $found = $this->entryFromLink($request, $entry);
        if (! $found instanceof WaitlistEntry) {
            return $found;
        }

        $status = $manage->confirm($found);

        if ($request->expectsJson()) {
            return response()->json(['message' => $status === EntryStatus::Confirmed ? 'You are on the waitlist.' : 'You left the waitlist earlier; sign up again to rejoin.']);
        }

        return redirect()->away($this->links->landing(
            $status === EntryStatus::Confirmed ? '/waitlist/confirmed' : '/waitlist/unsubscribed',
            $found->locale,
        ));
    }

    /** GET from the email footer, or POST from the mail provider's one-click button (RFC 8058). */
    public function unsubscribe(Request $request, string $entry, ManageEntry $manage): JsonResponse|RedirectResponse
    {
        $found = $this->entryFromLink($request, $entry);
        if (! $found instanceof WaitlistEntry) {
            return $found;
        }

        $manage->unsubscribe($found);

        if ($request->isMethod('POST') || $request->expectsJson()) {
            return response()->json(['message' => 'You left the waitlist.']);
        }

        return redirect()->away($this->links->landing('/waitlist/unsubscribed', $found->locale));
    }

    /**
     * The entry a signed link points at. A browser with a bad, expired or stale link goes back to the form
     * (it can sign up again); an API client gets a problem.
     */
    private function entryFromLink(Request $request, string $id): WaitlistEntry|RedirectResponse
    {
        $browser = ! $request->expectsJson() && $request->isMethod('GET');

        if (! $request->hasValidSignature()) {
            if ($browser) {
                return redirect()->away($this->links->landing('/waitlist', 'ar', ['link' => 'expired']));
            }
            throw Problem::forbidden('invalid-signature', 'This link is invalid or has expired.');
        }

        $entry = WaitlistEntry::query()->find($id);
        if ($entry === null) {
            if ($browser) {
                return redirect()->away($this->links->landing('/waitlist', 'ar', ['link' => 'expired']));
            }
            throw Problem::notFound();
        }

        return $entry;
    }
}
