# Analytics

Reports. **Now:** pilot results (#345), before vs after Trik per seller. Later: dashboards, exports.

**Phase:** Launch  ·  **Table prefix:** `analytics_`

## Pilot results

One page per seller that proves the promise, or doesn't. Every number comes from the **cash-on-delivery orders' own
statuses** (`Orders\Application\OrderStats`), which follow the store: nothing is estimated.

- **Go-live:** the date the seller sets (`PUT /v1/analytics/pilot`), or the first WhatsApp confirmation
  (`Confirmation\Application\ConfirmationStats::firstRunAt`).
- **Baseline:** orders placed in the `baseline_days` (default 90: what the store backfill imports) before go-live.
  **After:** orders placed since.
- **Rates** (fractions, null when there's nothing to measure, never 0):
  - confirmation = confirmed ÷ (confirmed + cancelled), where *confirmed* means reached confirmation or beyond;
  - cancellation = cancelled ÷ (confirmed + cancelled);
  - refusal = refused ÷ (delivered + refused): **refused at the door**, the pilot's promise.
  Orders still open, or still on the road, don't count yet (they'd flatter every recent day).
- **Time to confirm:** the median from order to confirmation, where Trik recorded the moment (`confirmed_at`).
- Days and weeks are **Cairo** days and weeks (Monday).

| | |
|---|---|
| `GET /v1/analytics/pilot` | `analytics.reports.view`: go-live, baseline, after, the change in points, the last 60 days |
| `PUT /v1/analytics/pilot` | `analytics.reports.manage`: `live_at` (null: first confirmation), `baseline_days` (14–180) |
| `GET /v1/analytics/pilot/weekly.csv` | `analytics.reports.view`: one row per week from the start of the baseline (UTF-8 with BOM for Excel; formula-looking cells neutralised) |
| `php artisan analytics:pilot-report [--out=]` | Every pilot (a go-live set, or confirmations sent) in one CSV, for the weekly pilot review |

Owners and admins see the results; agents don't.

**Accuracy:** delivered and refused come from the store. Shopify reports a delivery on the fulfillment
(`shipment_status` / `displayStatus` = delivered, mapped since this module); a refusal reaches Trik when the store records
it (EasyOrders statuses, or a courier integration later). Until then a refused Shopify order stays "shipped" and isn't
counted at the door.

## Tables

`analytics_pilots`: per workspace, `live_at` and `baseline_days`.
