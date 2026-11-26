# Orders

The order card, lifecycle states, order edits from chat, returns and exchanges.

**Phase:** Launch  ·  **Table prefix:** `orders_`  ·  **Feature flag:** `FEATURE_ORDERS`

## One way in

Every order enters through `RecordOrder::handle(OrderInput)`, whatever the source: Shopify (#332),
EasyOrders (#117) or created in chat. It is **idempotent per (source, external id)**: a redelivered or
updated store webhook updates the order instead of duplicating it.

On an update from a store:
- items, totals, courier and tracking follow the store;
- the status only moves forward, so a late webhook never moves a shipped order back;
- an address an agent fixed is kept.

Money is in minor units (piastres). `cod_amount` equals the total for cash-on-delivery orders.
The customer is resolved to a contact by phone, so the order shows on that customer's conversation.

## Lifecycle

`new → confirming → awaiting_deposit → confirmed → ready_to_ship → shipped → delivered → returned`, and
`cancelled` from anything before shipping (`Domain\OrderStatus`). Confirming is idempotent. Confirming
an order whose address is flagged answers `409 address-needs-fix`.

## The order card in chat (#105)

`GET /v1/inbox/conversations/{id}/orders` returns the customer's latest orders, each with:
- items, totals, the address (raw + structured + one Arabic line), courier and tracking;
- `next_action`, for the order bar: `fix_address`, `confirm` or `resend_deposit_link`.

Changes are pushed as `order.updated` on `inbox:{workspace}`.

## Address fix (#344)

`PATCH /v1/orders/{id}` with `address_needs_fix: true` flags an order (and `?needs_address_fix=1` filters
the list). `address: {governorate, area, street, building, floor, apartment, landmark}` sets the structured
address; governorate, area and street are required. The store's original text stays in `raw`.
Governorates: `GET /v1/addresses/governorates` (the 27, ISO 3166-2:EG codes).

## Public API (Application layer)

| Contract | Purpose |
|---|---|
| `RecordOrder` + `OrderInput` / `OrderItemInput` | the intake for every source (idempotent) |
| `ManageOrder` | confirm / cancel / move status, fix or flag the address, note (all on the timeline) |
| `OrderPresenter` | API shape; `forContact()` for the order card |

## Events

| Emits | Listens to |
|---|---|
| `OrderChanged` (created, updated, status_changed, address_fixed), after commit | — |
| `OrderConfirmed` → `order.confirmed.v1` (through the outbox once #29 lands) | — |
| real time: `order.updated` on `inbox:{workspace}` | — |

## HTTP API (`/v1/orders`, signed in, `X-Workspace-Id`)

| Method | Path | Permission |
|---|---|---|
| GET | `/orders?status=&contact_id=&needs_address_fix=1` | `orders.orders.view` |
| POST | `/orders` (create from chat, Idempotency-Key required) | `orders.orders.create` |
| GET / PATCH | `/orders/{id}` | `orders.orders.view` / `orders.orders.update` |
| POST | `/orders/{id}/confirm`, `/orders/{id}/cancel` | `orders.orders.confirm` |
| GET | `/orders/{id}/timeline` | `orders.orders.view` |

Agents have all four.

## Not yet

- The confirmation engine (#106): the template with Confirm / Change / Cancel, reminders, escalation.
- Writing statuses and fixed addresses back to the store (with the connectors, #332 #117).
