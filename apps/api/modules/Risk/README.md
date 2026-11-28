# Risk

Refusal risk score (#115): how likely a COD order is to be refused at the door, 0–100, with plain reasons. Later: deposit
rules, a shared risk network (opt-in).

**Phase:** Launch → Scale  ·  **Table prefix:** `risk_`  ·  **Feature flag:** `risk` (`FEATURE_RISK`, default `none`)

## The score (rules-v1)

Only the **seller's own data** (row-level security keeps every query in the workspace): the customer's history with this
seller, and the order itself. A baseline plus explainable rules; the reasons' points sum to the score, clamped to 0–100.

| Rule | Points (default) |
|---|---|
| baseline | 20 |
| `refused_before`: earlier orders refused at the door (returned) | +25 each, 2 counted |
| `cancelled_before` | +10 each, 2 counted |
| `unreachable_before`: earlier orders where the customer never answered the WhatsApp confirmation | +10 each, 2 counted |
| `delivered_before` | −10 each, 3 counted |
| `first_order` with this seller | +15 |
| `high_value`: COD ≥ 2× the seller's usual (median of their last 500 COD orders); half at 1.5×. Under 20 orders: ≥ 3,000 EGP | +15 |
| `address_incomplete`: no governorate and not deliverable (a third with a governorate) | +15 |
| `address_flagged` by an agent | +10 |
| `late_night`: placed 00:00–06:00 Cairo time | +5 |
| `prepaid` | keeps 30% of the score |

Sellers change any weight (−50…50) and the **threshold** (default 70) with `PUT /v1/risk/settings`; open orders are scored again.
Product category isn't scored yet: there is no product catalogue in Trik.

## When

`RiskListener` on `Orders\OrderChanged`: a new order, an edit (address, flags, an unanswered confirmation), and when an order
ends (delivered, refused, cancelled), the same customer's open orders. Finished orders keep their last score.
`risk:rescore [workspace…]` scores open orders after turning the feature on.

Each score is stored in `risk_assessments` (score, reasons, the inputs it read, model version) and on
`orders_orders.risk_score` (the board's `min_risk` filter). The first time an order reaches the threshold, a `risk_flagged` entry
lands on its timeline.

## HTTP

| | |
|---|---|
| `GET /v1/orders/{order}/risk` | `orders.orders.view`: score, threshold, flagged, top 3 reasons and all of them, each with Arabic and English text |
| `GET /v1/risk/settings` | `risk.settings.view` |
| `PUT /v1/risk/settings` | `risk.settings.manage`: threshold, weights |

Owners and admins manage the settings; agents see each order's risk.

## Public API

| Class | Purpose |
|---|---|
| `Domain\RiskScorer` | Pure rules: `RiskInput` + `RiskWeights` → `RiskScore` |
| `Application\ScoreOrder` | Scores an order inside its workspace and stores the result |
| `Application\RiskReasons` | Reason codes → Arabic / English text |
