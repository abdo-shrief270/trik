# Commerce

Store connectors: **Shopify** (#332) now; EasyOrders (#117), WooCommerce, Salla and Zid later, the same shape.
Orders from a store enter Trik through `Orders\Application\RecordOrder` (idempotent per source + external id).

**Phase:** Launch → Growth  ·  **Table prefix:** `commerce_`  ·  **Feature flag:** `commerce` (`FEATURE_COMMERCE`, default `none`)

## Tables

| Table | |
|---|---|
| `commerce_stores` | A connected shop: domain, encrypted offline token, granted scopes, status, backfill progress. One **active** connection per shop across all workspaces (partial unique index), so a shop's webhooks route to exactly one place. |
| `commerce_webhook_events` | Raw deliveries, unique per (store, `X-Shopify-Webhook-Id`): a retried delivery is stored once and processed once. Processed ones are pruned after 30 days. |
| `commerce_order_versions` | The store's last applied `updated_at` per order. A late, replayed or out-of-order payload (or the backfill, reading orders webhooks have since updated) is **stale** and dropped instead of rolling the order back. |

`commerce_store_route(platform, shop)` is a narrow `SECURITY DEFINER` lookup (workspace and store ids, active stores only) for
webhooks and installs, which run before any workspace is active.

## Shopify

**Connecting** (OAuth authorization code grant, offline token, non-embedded app):

1. The web app calls `POST /v1/commerce/shopify/install {shop}` (`commerce.stores.manage`) and sends the browser to the returned
   `authorize_url`. Trik remembers (workspace, user, shop) under a random single-use state for 10 minutes.
2. Shopify redirects to `GET /webhooks/shopify/oauth/callback`. Refused unless Shopify's HMAC, the shop (`*.myshopify.com` only:
   the client secret is sent to that host), the age, the state (single use, same shop) and the member's permission all check out.
   The code becomes a token (stored with Laravel's `encrypted` cast), the store becomes active, and the backfill is queued.
3. The browser lands on `{FRONTEND_URL}/settings/stores?shopify=connected&store=<id>` or `?shopify_error=<code>`.

A seller arriving from Shopify (install link, app list) hits the app URL `GET /webhooks/shopify/app`, which checks the HMAC and
sends them to `{FRONTEND_URL}/settings/stores?connect=shopify&shop=<shop>` to sign in, pick the workspace and continue at step 1.

**Webhooks** (`POST /webhooks/shopify`, topic in `X-Shopify-Topic`): HMAC-verified against the app secret (and the previous one
while rotating), routed by `X-Shopify-Shop-Domain`, stored, answered at once; `ProcessStoreWebhooks` drains them per workspace on
the queue (one transaction per delivery, SKIP LOCKED, retried with backoff, parked after 5 attempts; `commerce:sweep` wakes
stranded ones every minute).

| Topic | What happens |
|---|---|
| `orders/create`, `orders/updated`, `orders/cancelled` | Mapped (`ShopifyOrderMapper`) and imported (`ImportStoreOrder`): `recorded`, `stale`, or `skipped:no-phone` / `skipped:no-items` |
| `fulfillments/create`, `fulfillments/update` | The order is re-read through GraphQL (`RefreshShopifyOrder`, one per order while queued) for status and tracking |
| `app/uninstalled` | Store `uninstalled`, token dropped. A 401 from Shopify on any call does the same |

**Mapping rules:** phone from the shipping address, then billing, customer, order; paid / authorized / refunded is prepaid, anything
else cash on delivery; cancelled → `cancelled`, fulfilled → `shipped`, otherwise the status is Trik's; current quantities (lines
edited to 0 dropped); the province code is the governorate (Shopify's Egyptian codes are the ISO 3166-2:EG suffixes Trik uses),
city → area, address1 → street, the full text in `raw`.

**Backfill:** `BackfillShopifyOrders` starts a GraphQL bulk operation over the last 90 days (60 unless Shopify granted
`read_all_orders`: it serves no older orders), `PollShopifyBackfill` waits for it, `ImportShopifyBackfill` streams the JSONL and
imports order by order (line items are separate lines; an item arriving after its order was flushed sends that order to a
refresh). Old orders never get a confirmation message: the engine skips orders placed more than
`CONFIRMATION_MAX_ORDER_AGE_HOURS` (48) ago.

**GraphQL Admin API only** (`ShopifyAdminClient`), with cost-based limiting (`CostThrottle`): each shop's bucket from the last
`throttleStatus` is kept in the cache; a call that wouldn't fit waits up to 2 s, longer waits (and `THROTTLED` answers) release the
job for the right number of seconds.

### Setting up the app (once)

1. Create the app in the Shopify Dev Dashboard; custom distribution for pilots, the App Store later.
2. Deploy `shopify/shopify.app.toml` with the Shopify CLI (URLs, scopes, webhook subscriptions), after setting `client_id` and the
   API host.
3. Request access to **protected customer data** (name, phone, address) in the Partner Dashboard: without it Shopify returns those
   fields empty and every order is skipped as `no-phone`. Request `read_all_orders` for 90-day backfills.
4. Set `SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET` (and `SHOPIFY_PREVIOUS_CLIENT_SECRET` while rotating), then turn the
   `commerce` feature on for pilot workspaces.

## Public API (Application layer)

| Class | Purpose |
|---|---|
| `ImportStoreOrder` | Versioned, locked import of a mapped store order into Orders |
| `Shopify\ShopifyOrderMapper` | Webhook (REST JSON) or GraphQL order → `OrderInput` |
| `Shopify\ShopifyInstall` | OAuth start / finish, app launch verification |

## Permissions

| Permission | |
|---|---|
| `commerce.stores.view` | See connected stores and their import status |
| `commerce.stores.manage` | Connect, disconnect and re-import stores |

Owners and admins have both; agents neither.

## Events

| Emits | Listens to |
|---|---|
| (through `RecordOrder`: `OrderChanged`) | — |

## Privacy webhooks (#333)

`POST /webhooks/shopify/compliance` takes Shopify's mandatory `customers/data_request`, `customers/redact` and `shop/redact`.
Verified like any webhook (401 otherwise), then **every workspace that ever held the shop** (`commerce_store_history()`, any
status: the data outlives an uninstall) gets a privacy request in the Compliance module, which exports or redacts across
modules (see its README). `shop/redact` is skipped where the store is active again (reinstalled within Shopify's 48 hours).
Unknown shops are acknowledged with 200, as Shopify's review tests the endpoint with made-up shops.

## Not yet

Status and address write-back to Shopify; products and stock.
