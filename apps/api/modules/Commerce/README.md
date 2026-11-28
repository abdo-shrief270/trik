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

## Write-back (#116, #344)

When something happens to a Shopify order **in Trik** (an agent, the confirmation engine), `WriteBackListener` queues
`WriteBackShopifyOrder`, which reads the order's state when it runs (a burst collapses into one write):

| In Trik | In Shopify | Setting (`PATCH /v1/commerce/stores/{store}`) |
|---|---|---|
| Confirmed | tag `trik-confirmed` (and `trik-cancelled` removed) | `tag_orders`, default on |
| Cancelled | tag `trik-cancelled` (and `trik-confirmed` removed) | `tag_orders`, default on |
| Cancelled | `orderCancel`: restocked, no refund, customer not emailed, staff note "Cancelled in Trik"; skipped if Shopify already cancelled it | `cancel_in_store`, **default off** |
| Address fixed | the shipping address, keeping Shopify's name, phone and postcode (`orderUpdate` replaces the whole address) | `write_address`, default on |

Each write, or Shopify's refusal, lands on the order timeline (`store_updated`, `store_update_failed`). Throttling and outages
retry; a revoked token marks the store uninstalled. **No loops:** `OrderChanged` carries an `origin`; changes Trik applied
from a store (`RecordOrder`) have it set and are never written back, so Shopify's own `orders/updated` echo stops there.

## EasyOrders (#117)

Built from EasyOrders' public API docs (https://public-api-docs.easy-orders.net). What they offer shapes it:

- **Connect:** `POST /v1/commerce/easyorders/install` returns EasyOrders' *authorized app link*
  (`app.easy-orders.net/#/install-app?…`). The seller accepts; EasyOrders creates an API key (`orders:read,orders:update`)
  and the order webhooks, and POSTs `{api_key, store_id}` to `POST /webhooks/easyorders/install/{token}`. EasyOrders signs
  nothing, so the token in that URL is the authentication: random, single use, 30 minutes, tied to the workspace and the
  member (whose permission is checked again). The key is stored encrypted. One active connection per EasyOrders store.
- **Webhooks:** `POST /webhooks/easyorders/{token}` (order created, order status changed), routed by the SHA-256 of the
  store's own token (`commerce_store_by_hook()`, active stores only). A webhook set up by hand sends EasyOrders' `secret`
  header; store it in `webhook_secret` and it is checked. **The body is never trusted:** only the order id is taken, and
  `RefreshEasyOrdersOrder` reads the order back with the store's key (EasyOrders' own integration guide does the same), checks
  it belongs to the store, and imports it (versioned, like Shopify). Deliveries are de-duplicated by body hash (EasyOrders
  sends no delivery id).
- **Mapping:** statuses (`confirmed`, `processing`/`waiting_for_pickup` → ready to ship, `in_delivery` → shipped, `delivered`,
  `canceled`, returns and refunds → returned; `pending` and payment states left to Trik); `cod` vs anything else or `paid` →
  prepaid; the discount from `cost + shipping_cost − total_cost`; the free-text `government` through `Governorate::fromName()`;
  variants as "color: أزرق · size: M".
- **Write-back** (settings `write_status`, `write_address`, both on): confirmed / canceled through
  `PATCH /orders/:id/status`; a fixed address as a **private order note** (the API can't edit addresses).
- **Limits:** EasyOrders allows 40 calls a minute per key; the client keeps each store under 35 (`EASYORDERS_CALLS_PER_MINUTE`)
  and releases the job until the next minute. 401/403 mark the store uninstalled.
- **No backfill:** the API has no way to list orders (`backfill-unsupported`). New orders arrive from the moment of install.

## Not yet

Products and stock; editing items from chat (`PATCH /orders` doesn't change items yet); an EasyOrders backfill if they add a
list endpoint.
