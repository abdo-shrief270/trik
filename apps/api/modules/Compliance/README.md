# Compliance

Privacy requests, retention and policy guardrails. **Now:** the privacy requests store platforms send (Shopify's mandatory
webhooks, #333). Later: direct requests from customers, retention settings.

**Phase:** Launch  ·  **Table prefix:** `compliance_`

## How a privacy request runs

1. A platform webhook arrives (`POST /webhooks/shopify/compliance`, see the Commerce README) and `RecordPrivacyRequest` stores it
   **once per delivery** in each workspace concerned: kind, origin, and the subject (phones, order ids, store), **encrypted**.
   Audited as `compliance.request_received`.
2. `ProcessPrivacyRequest` (queue, 5 tries with backoff) widens the subject through every registered
   `App\Support\Privacy\PersonalDataSource` until it stops changing (a store → its order ids → their phones), then:
   - `data_request`: asks each source to **export**; the result is kept encrypted for **30 days** (`compliance:prune` drops it
     daily) for the store owner to download. Audited as `compliance.data_exported`;
   - `customer_redact`, `shop_redact`: asks each source to **redact**, in one transaction. Audited as `compliance.data_redacted`
     with the counts per source.
   Once done, the request no longer names anyone (the subject is cleared).

| Kind | Sources do |
|---|---|
| Orders | Exports the subject's orders from that source. Redaction anonymises the customer on those orders (name, phone, address, note, and free-text `reason`s in the timeline through the narrow `orders_redact_event_reasons()`, as the timeline is append-only); items, amounts and dates stay for the seller's books. Chat orders are the seller's own records and are not touched by a store's request. |
| Contacts | Exports the contact; redaction clears the name. The phone stays: it is the seller's own WhatsApp conversation with the person. |
| Commerce | Redaction deletes raw store deliveries about the subject; `shop_redact` deletes the store record (with its deliveries and order versions). |

A module joins by implementing `PersonalDataSource` and registering it in its provider:
`app(PersonalDataRegistry::class)->register(MySource::class)`.

## HTTP

| | |
|---|---|
| `GET /v1/compliance/requests` | `compliance.requests.view`: requests, status and counts, never the subject |
| `GET /v1/compliance/requests/{id}/export` | `compliance.requests.export`: the data export, as an attachment, `no-store`; the download is audited |

Owners and admins have both permissions; agents neither.

## Tables

`compliance_requests`: one row per request per workspace, unique per (origin, delivery); `subject` and `export` encrypted.

## Not yet

Direct requests from customers (and full erasure of a contact and their WhatsApp history); retention settings; a web screen
for the requests (the export endpoint is ready for it).
