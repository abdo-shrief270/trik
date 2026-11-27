# Waitlist

The public pilot waitlist on trik.chat: double opt-in sign-up, the signed confirm and unsubscribe links,
and an export for the team (#102).

**Phase:** Launch (MVP · Pilot)  ·  **Table:** `waitlist_entries` (not tenant data: no workspace, no RLS)  ·
**Feature flag:** `FEATURE_WAITLIST` (`all` while open, `none` closes it)

## Endpoints (public, no sign-in)

| | |
|---|---|
| `POST /v1/waitlist` | Join. Always `202` with the same body (see below). Throttle `waitlist`: 5/min and 30/h per IP, 5/h per email. |
| `GET /v1/waitlist/{entry}/confirm` | Signed link from the email (7 days). Browser → landing `/waitlist/confirmed`; JSON → `200`. |
| `GET\|POST /v1/waitlist/{entry}/unsubscribe` | Signed link in every email, never expires. `POST` is RFC 8058 one-click from Gmail / Yahoo. |

A bad, expired or stale link sends a browser back to the landing form (`/waitlist?link=expired`) and gives
an API client `403 invalid-signature` (or `404`). Landing pages follow the entry's language (`/` or `/en`).

## Sign-up rules (`Application\JoinWaitlist`)

The answer never depends on the email, so the endpoint can't be used to find out who signed up.

| Entry | What happens |
|---|---|
| new | `pending`, confirmation email |
| `pending` | email again after a 10-minute cooldown, at most 5 per opt-in cycle; **details are not overwritten** (anyone can type someone else's email) |
| `confirmed` | nothing |
| `unsubscribed` | back to `pending` with the new details and consent, new cycle and email (only the address owner can unsubscribe, so this is a real new opt-in) |

An old confirm link never puts someone who has since unsubscribed back on the list. Concurrent sign-ups for the
same new email are safe (row lock + unique email; the loser answers the same `202`).

## Email

`Infrastructure\Notifications\WaitlistConfirmation`: queued, `afterCommit`, 5 tries with backoff. It carries
only the entry id and skips sending if the entry is no longer `pending` when the worker runs. Arabic (RTL) or
English by the entry's `locale`, HTML + text parts (`resources/views`), `List-Unsubscribe` and
`List-Unsubscribe-Post` headers. Copy lives in `WaitlistCopy` (keep both languages in step).

## Privacy

- Stored: name, store name, email (lower-cased), E.164 phone, platform, order band, language, consent time
  and IP (evidence of the opt-in), status timestamps.
- Unconfirmed entries are deleted 30 days after their last change (`model:prune`, daily 03:40).
- Names are cleaned to one line of plain text; the email HTML escapes everything.

## Commands

```bash
php artisan waitlist:export > waitlist.csv            # confirmed entries (default)
php artisan waitlist:export --status=all > all.csv    # pending | unsubscribed | all
```

Free-text cells are guarded against spreadsheet formulas.

## Configuration

`LANDING_URL` (where links land; default `http://localhost:3100`), `CORS_ALLOWED_ORIGINS` must include the
landing origin, `FEATURE_WAITLIST`. Mail goes through the normal mailer and queue.
