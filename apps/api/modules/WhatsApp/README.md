# WhatsApp

Connected numbers, sending, templates, media and webhook intake — behind one provider contract,
so the MVP runs on a BSP (360dialog, [ADR 0009](../../../../docs/architecture/adr/)) and later
moves to Trik's own Meta Cloud API access without feature code changes.

**Phase:** Launch · **Table prefix:** `whatsapp_`

## Providers

| Provider | `provider` value | Webhook | Auth | Missing capabilities |
|---|---|---|---|---|
| 360dialog (MVP) | `360dialog` | per number: `POST /webhooks/whatsapp/360dialog/{number}` | `D360-API-KEY` per channel; webhook secret header set by Trik | `number_info` |
| Meta Cloud API | `meta_cloud` | one per app: `GET/POST /webhooks/whatsapp/meta_cloud` | bearer token; `X-Hub-Signature-256` with the app secret | `configure_webhook` (set in the Meta dashboard) |
| Fake (not in production) | `fake` | per number, like 360dialog | none | — |

Moving a number to another provider = update its `provider` and `credentials`. Nothing else changes.

Every adapter passes the same suite: [`tests/Feature/ProviderContractTest.php`](tests/Feature/ProviderContractTest.php).
To add a provider: implement `WhatsAppProvider` (+ a `WebhookAdapter`), register both in `config/whatsapp.php`,
add it to the `providers` dataset and its fake API to `tests/Fixtures/ProviderFixture.php`.

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `Contracts\WhatsAppProvider` | send (text, template, buttons, media), mark read, templates, media download, number info, webhook setup |
| `Contracts\WebhookAdapter` | verify a delivery, normalise it to `InboundMessage` / `StatusUpdate` |
| `ProviderRegistry` | the adapter for a connected number |
| `ConnectNumber` | connect a number with provider credentials (pilot onboarding) |
| `SendMessage` | log as queued and send on the queue with retries |

## Events

| Emits | Listens to |
|---|---|
| `MessageReceived` (once per message; redeliveries dropped) | — |
| `MessageStatusChanged` (sent → delivered → read, or failed; never backwards) | — |

## How a webhook flows

1. The controller finds the workspace with a narrow `SECURITY DEFINER` function (no cross-workspace reads),
   activates it, verifies the signature/secret, stores the raw payload and answers `200`.
2. `ProcessWebhookEvent` (queue) parses it, inserts inbound messages (unique per provider id),
   applies statuses, marks the delivery processed and publishes events.
3. If processing fails, the raw delivery stays unprocessed and the job retries with backoff.

Workers must listen on `WHATSAPP_QUEUE` (default `default`).

## HTTP API (`/api/v1`, signed in, `X-Workspace-Id` required)

| Method | Path | |
|---|---|---|
| GET / POST | `/whatsapp/numbers` | list / connect |
| GET / POST | `/whatsapp/numbers/{id}/messages` | message log / send (`type`: text, template, buttons, media) |
| GET / POST / DELETE | `/whatsapp/numbers/{id}/templates[/{name}]` | templates |

## Not yet

- In-app embedded onboarding (360dialog Integrated Onboarding / Meta Embedded Signup) — pilot numbers are connected with `POST /whatsapp/numbers`.
- Per-number send rate limits and cost import (#330).
- Endpoint paths for 360dialog templates, media and webhook config follow its v2 docs; check them against a sandbox key once the partner agreement (#328) is signed.
