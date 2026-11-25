# Backend modules

| Module | Responsibility | Phase |
|---|---|---|
| [`Identity`](Identity/README.md) | Users, teams, roles and permissions, two-step sign-in, single sign-on, audit log. | Launch |
| [`Workspaces`](Workspaces/README.md) | Seller workspaces (tenants), multiple brands, agencies and partner console. | Launch → Scale |
| [`WhatsApp`](WhatsApp/README.md) | Embedded signup, coexistence with the WhatsApp Business app, numbers, templates, quality and limits. | Launch |
| [`Channels`](Channels/README.md) | Instagram, Messenger, website chat, SMS and email, behind one channel interface. | Launch → Scale |
| [`Inbox`](Inbox/README.md) | Conversations, assignment, internal notes, quick replies, the 24-hour window, snooze and search. | Launch |
| [`Contacts`](Contacts/README.md) | Customer profiles, consent ledger, segments, activity timeline, deals. | Launch |
| [`Orders`](Orders/README.md) | The order card, lifecycle states, order edits from chat, returns and exchanges. | Launch |
| [`Confirmation`](Confirmation/README.md) | Confirmation engine, ladder (WhatsApp, reminder, call, SMS), call queue, auto-cancel. | Launch → Growth |
| [`Risk`](Risk/README.md) | Refusal risk score with reasons, flags, deposit rules, shared risk network (opt-in). | Launch → Scale |
| [`Addresses`](Addresses/README.md) | Arabic address capture, normalisation, confidence score, local address data. | Launch |
| [`Commerce`](Commerce/README.md) | Store connectors (Shopify, EasyOrders, WooCommerce, Salla, Zid), built-in catalogue, stock. | Launch → Growth |
| [`Couriers`](Couriers/README.md) | Courier connectors, shipment tracking, scorecard by area, courier suggestion, delivery rescue. | Growth |
| [`Payments`](Payments/README.md) | Gateways (Paymob, Kashier, Fawry), payment links, deposits into the seller's own account. | Growth |
| [`Campaigns`](Campaigns/README.md) | Broadcasts, segments, cost estimate before sending, frequency caps, attribution. | Launch |
| [`Automations`](Automations/README.md) | Flow builder, triggers, journeys, versions and test mode. | Growth |
| [`Ai`](Ai/README.md) | AI agent: knowledge, Arabic dialects, voice notes, order actions, guardrails, handoff. | Growth |
| [`MessageCosts`](MessageCosts/README.md) | Live cost meter, forecasts, budget caps, Meta billing status. Meta bills the seller directly. | Launch |
| [`Analytics`](Analytics/README.md) | Profit per order, funnels, agent and campaign reports. Writes facts to ClickHouse. | Launch → Growth |
| [`Billing`](Billing/README.md) | Plans, the order bundle, idempotent usage metering, invoices, local payment methods. | Launch |
| [`Developer`](Developer/README.md) | Public REST API, API keys and scopes, outbound webhooks, embeddable widgets. | Growth |
| [`Compliance`](Compliance/README.md) | Data requests, retention, encryption settings, privacy and policy guardrails. | Launch |

Each module follows the same layout: `src/{Domain,Application,Infrastructure,Http}`, `database/migrations`, `tests/{Unit,Feature}`.
