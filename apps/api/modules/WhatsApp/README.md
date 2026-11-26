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

## Storage (shaped for [ADR 0010](../../../../docs/architecture/adr/0010-scale-path-30k-messages-per-second.md))

| Table | What | Partitioned |
|---|---|---|
| `whatsapp_numbers` | connected numbers, encrypted credentials | no |
| `whatsapp_webhook_events` | raw deliveries; dropped after `WHATSAPP_WEBHOOK_RETENTION_MONTHS` | monthly |
| `whatsapp_messages` | conversation messages (inbox), both directions | monthly |
| `whatsapp_broadcast_sends` | campaign sends, one row per contact; kept apart from conversations | monthly |
| `whatsapp_inbound_keys` | de-duplication of inbound provider ids (pruned after 30 days) | no |

`php artisan whatsapp:maintain` (scheduled daily) creates partitions three months ahead, drops expired ones,
prunes keys and complains if rows ever land in a `_default` partition.

## How a webhook flows

1. The controller finds the workspace with a narrow `SECURITY DEFINER` function (no cross-workspace reads),
   activates it, verifies the signature/secret, stores the raw payload and answers `200`.
2. `ProcessWebhookEvents` (one per workspace at a time) drains pending deliveries **500 at a time** with
   `SKIP LOCKED`. A batch costs a fixed number of queries whatever its size: one de-duplication insert,
   one message insert, one `UPDATE … FROM (VALUES …)` per table for statuses.
3. A delivery that fails is retried on its own, the rest go through; after 5 attempts it is parked.
4. Events: `MessageReceived` / `MessageStatusChanged` per conversation message,
   `BroadcastProgressed` per broadcast per batch (never per campaign message).

## Broadcasts

`SendBroadcast` inserts the sends in bulk and queues chunk jobs of 200. Each job keeps to the number's
messages-per-second limit (`WHATSAPP_DEFAULT_MPS`, Meta's default 80) and continues a second later when it
hits it. At the Scale stage the Go dispatcher (#97) takes over sending from the same table.

Workers must listen on `WHATSAPP_QUEUE` (default `default`).

## HTTP API (`/v1`, signed in, `X-Workspace-Id` required)

| Method | Path | |
|---|---|---|
| GET / POST | `/whatsapp/numbers` | list / connect |
| GET / POST | `/whatsapp/numbers/{id}/messages` | message log / send (`type`: text, template, buttons, media) |
| GET / POST / DELETE | `/whatsapp/numbers/{id}/templates[/{name}]` | templates |

## Not yet

- In-app embedded onboarding (360dialog Integrated Onboarding / Meta Embedded Signup) — pilot numbers are connected with `POST /whatsapp/numbers`.
- Per-number send rate limits and cost import (#330).
- Endpoint paths for 360dialog templates, media and webhook config follow its v2 docs; check them against a sandbox key once the partner agreement (#328) is signed.
