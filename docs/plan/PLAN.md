# Trik delivery plan

This plan turns the product specification into GitHub milestones and issues. It is generated from `docs/plan/plan.json`; GitHub is the live source after import.

## Phases

| Phase | Milestones | Goal |
|---|---|---|
| **Launch** | M0–M4 | Enough to charge for: WhatsApp inbox, confirmation engine, risk score, store connectors, campaigns with cost control, billing, mobile apps, landing page |
| **Growth** | M5–M7 | Hard to leave: deposits, couriers, true profit, flows, AI agent, public API, Gulf |
| **Scale** | M8 | Bigger accounts and new markets |

## Milestones

| Milestone | Target date | Issues | Scope |
|---|---|---|---|
| **M0 · Foundations** | 2026-12-20 | 26 | Repository, CI/CD, staging in the EU region, Laravel/Nuxt/Flutter skeletons, tenancy, auth, design system, Go service skeletons, Meta Tech Provider application. |
| **M1 · Launch α · WhatsApp, inbox & contacts** | 2027-01-31 | 60 | A seller connects WhatsApp through Embedded Signup and the team answers customers in a shared inbox. Contacts, teams and roles, Arabic-first UI, waitlist landing page. |
| **M2 · Launch β · Orders & confirmation** | 2027-03-07 | 22 | Store connectors, order card, confirmation engine and board, refusal risk score, address normalisation, selling in chat. |
| **M3 · Launch γ · Campaigns, costs, analytics & billing** | 2027-04-11 | 34 | Broadcasts with cost estimate, cost meter and budget caps, owner dashboard and reports, plans with order bundle and usage meter, recipes, Messaging API. |
| **M4 · Public launch · Mobile, landing & readiness** | 2027-05-16 | 33 | Mobile apps in the stores, full landing page with pricing, security and privacy basics, pilot with 10–20 sellers, load and security tests, go-live. |
| **M5 · Growth 1 · Deposits, couriers & true profit** | 2027-07-11 | 44 | Deposits and payment gateways, call queue and confirmation ladder, courier integrations and scorecard, delivery rescue, true profit per order. |
| **M6 · Growth 2 · Automations, AI agent & campaigns** | 2027-09-05 | 35 | Visual flow builder, AI agent with dialects and order actions, advanced campaigns, inbox AI assist. |
| **M7 · Growth 3 · Teams, developers, Gulf & mobile** | 2027-10-31 | 32 | Public API and webhooks, supervisor tools, multiple workspaces, Salla and Zid, Gulf localisation, mobile offline and dashboard. |
| **M8 · Scale** | 2028-02-28 | 30 | Voice confirmation, shared risk network (after legal clearance), partners and white label, new channels, regional hosting, SSO. |

Target dates assume a team of 2 backend, 1–2 web, 1 mobile and 1 designer/QA working in two-week sprints. Re-plan at the end of every milestone.

## Numbers

- Epics: **22**
- Issues: **316** (273 features, 25 technical, 18 business/QA tasks)
- Api: 260 issues
- Design: 2 issues
- Infra: 16 issues
- Landing: 28 issues
- Mobile: 39 issues
- Services: 18 issues
- Web: 234 issues

## Requirements (non-functional)

| Area | Requirement |
|---|---|
| Latency | Webhook acknowledged < 50 ms p99; message visible in inbox < 1 s p95; API p95 < 300 ms |
| Throughput | Pilot: a few hundred msg/s on one server. Target: **30,000 sends/s peak** (≈ 100k webhook events/s) on the ADR 0010 scale path, staged by measured load |
| Cost | Hostinger first (≈ $460/month at the 30k/s peak), AWS when an ADR 0011 trigger is met |
| Availability | 99.9 % monthly at launch; 99.95 % on higher plans later |
| Data | RPO ≤ 5 min, RTO ≤ 1 h; PITR backups; restore drill every quarter |
| Security | Tenant isolation by RLS; encryption in transit and at rest; 2FA; audit log; annual pentest |
| Privacy | Egypt PDPL compliance; cross-border transfer permit; data export and deletion on request |
| Meta | Tech Provider; sellers billed by Meta directly; opt-in enforced; quality rating protected |
| Localisation | Arabic (RTL) default, English; Egyptian and Gulf formats |
| Quality | CI green; tests for every feature; architecture tests for module boundaries; feature flags |

## Scale path and hosting (ADR 0010, ADR 0011)

| Stage | Trigger | Adds | Issues |
|---|---|---|---|
| Pilot | — | Laravel + Redis on one Hostinger KVM 8 (EU), backups to S3 | #346, #350, #352, #356 |
| Launch | > ~1–2k events/s or queue lag > 5 s | Go webhook-intake + Redpanda, servers from Ansible | #95, #29, #351, #353 |
| Scale | > ~10k events/s or first 30k/s campaign | Go message-writer + dispatcher, ClickHouse status facts | #96, #97, #354 |
| AWS | an ADR 0011 trigger | RDS, MSK, ECS, service by service | #355 |

## Labels

`type:*` · `phase:*` · `platform:*` (api, web, mobile, landing, services, infra, design) · `module:*` (19 product modules) · priority `P0`/`P1`/`P2`

## Issues by milestone

### M0 · Foundations

- **API: authentication (web SPA + mobile tokens)** — P0 · api
- **API: conventions — errors, pagination, idempotency, versioning** — P0 · api
- **API: feature flags** — P1 · api
- **API: logging, metrics, tracing, error tracking** — P1 · api, infra
- **API: module loader and architecture tests** — P0 · api
- **API: roles and permissions base** — P0 · api
- **API: scaffold Laravel 13 with Octane (FrankenPHP)** — P0 · api
- **API: transactional outbox and Redpanda publisher** — P0 · api
- **API: workspaces (multi-tenancy) with row-level security** — P0 · api
- **Design: tokens pipeline (JSON → CSS + Dart)** — P1 · design
- **Infra: CI pipelines for api, web, services, mobile** — P0 · infra
- **Infra: continuous deployment to staging and production** — P0 · infra
- **Infra: domain, email and DNS for trik.chat** — P1 · infra
- **Infra: staging environment in an EU region (Terraform)** — P0 · infra
- **Mobile: Trik theme, l10n and RTL** — P0 · mobile
- **Mobile: scaffold Flutter app with flavors** — P0 · mobile
- **Services: shared Go platform package** — P1 · services
- **Web: typed API client and error handling** — P0 · web
- **Web: app shell — rail, side filters, content, workspace switcher** — P0 · web
- **Web: design system components (Trik tokens)** — P0 · web, design
- **Web: sign-up, sign-in, reset, invite acceptance** — P0 · web
- **Company, tax and bank setup** — P0 · 
- **Meta Business verification** — P0 · 
- **Trademark and domain protection** — P0 · 
- **Choose the MVP WhatsApp provider (BSP) and sign the partner agreement** — P0 · 
- **Encryption** — P0 · api, infra

### M1 · Launch α · WhatsApp, inbox & contacts

- **API: factories and synthetic Egyptian seed data** — P1 · api
- **API: media storage (S3/MinIO) and virus scanning** — P1 · api
- **Infra: monitoring, dashboards and alerts** — P1 · infra
- **Web: real-time with Centrifugo** — P0 · web
- **Arabic and English interface** — P0 · api, web, mobile
- **Local address data** — P1 · api, web, mobile
- **Local formats** — P1 · api, web, mobile
- **Phone number handling** — P1 · api, web, mobile
- **Facebook Messenger** — P0 · api, web, services
- **Instagram messages** — P0 · api, web, services
- **WhatsApp** — P0 · api, web, services
- **Activity timeline** — P1 · api, web
- **Consent ledger** — P1 · api, web
- **Customer value** — P1 · api, web, mobile
- **Dynamic segments** — P1 · api, web
- **Import and export** — P1 · api, web
- **Risk flags** — P1 · api, web
- **Unified profile** — P1 · api, web, mobile
- **Mobile: sign-in, workspace switch, biometrics-ready session** — P0 · mobile
- **Services: dispatcher — send within Meta limits** — P0 · services
- **Services: message-writer — batch persist messages** — P0 · services
- **Services: webhook-intake — Meta signature verification and produce to Redpanda** — P0 · services
- **24-hour window timer** — P0 · api, web, mobile
- **Bulk actions** — P0 · api, web
- **Collision detection** — P0 · api, web
- **Customer side panel** — P0 · api, web
- **Full media support** — P0 · api, web, mobile
- **Inbox views** — P0 · api, web
- **Interactive messages** — P0 · api, web
- **Internal notes and mentions** — P0 · api, web, mobile
- **Keyboard shortcuts** — P0 · api, web
- **One thread per customer** — P0 · api, web, mobile
- **Quick replies** — P0 · api, web, mobile
- **Search** — P0 · api, web, mobile
- **Smart assignment** — P0 · api, web
- **Snooze and reminders** — P0 · api, web, mobile
- **Spam and block list** — P0 · api, web
- **Tags, status and priority** — P0 · api, web, mobile
- **Working hours and shifts** — P0 · api, web
- **Shopify: request Protected Customer Data access (Level 2)** — P0 · 
- **Landing: brand copy in Arabic and English** — P1 · landing
- **Landing: scaffold `apps/landing` (Nuxt static + content)** — P0 · landing
- **Landing: waitlist page with double opt-in** — P0 · landing
- **Meta Tech Provider application and App Review** — P0 · 
- **BSP adapter: onboarding, messaging, templates and webhooks** — P0 · api, services, web
- **Coexistence mode** — P0 · api, web
- **Contact import** — P0 · api, web
- **Embedded signup** — P0 · api, web
- **Number health panel** — P0 · api, web
- **Number migration** — P0 · api, web
- **Setup checklist** — P0 · api, web
- **Template library** — P0 · api, web
- **Template manager** — P0 · api, web
- **WhatsApp provider abstraction: BSP now, Meta Cloud API later** — P0 · api, services
- **Agent status and capacity** — P1 · api, web
- **Audit log** — P1 · api, web
- **Data masking** — P1 · api, web
- **Roles and permissions** — P0 · api, web
- **Teams** — P1 · api, web
- **Two-step sign-in** — P1 · api, web, mobile

### M2 · Launch β · Orders & confirmation

- **Built-in catalogue** — P0 · api, web
- **Custom store connector** — P0 · api, web
- **EasyOrders** — P0 · api, web
- **Google Sheets sync** — P0 · api, web
- **Shopify** — P0 · api, web
- **Shopify app: Partner app, OAuth install and GraphQL Admin API client** — P0 · api
- **Shopify: embedded app pages in the Shopify admin** — P1 · web
- **Shopify: mandatory privacy webhooks** — P0 · api
- **Shopify: pilot installs through custom distribution** — P0 · api
- **WooCommerce** — P0 · api, web
- **Address capture** — P0 · api, web
- **Address confidence check** — P0 · api, web
- **Address normalisation** — P0 · api, web
- **Confirmation board** — P0 · api, web, mobile
- **Confirmation engine** — P0 · api, web, services
- **Create order from chat** — P0 · api, web
- **Edit order in chat** — P0 · api, web, mobile
- **Order card in chat** — P0 · api, web, mobile
- **Refusal risk score** — P0 · api, web
- **Review request** — P0 · api, web
- **Shipping notifications** — P0 · api, web
- **Shipping calculator** — P1 · api, web

### M3 · Launch γ · Campaigns, costs, analytics & billing

- **API: internal admin console** — P1 · api
- **Agent performance** — P1 · api, web
- **Campaign results** — P1 · api, web
- **Confirmation report** — P1 · api, web
- **Message cost report** — P1 · api, web
- **Order funnel** — P1 · api, web
- **Owner dashboard** — P1 · api, web, mobile
- **Keyword and FAQ rules** — P1 · api, web
- **Recipe library** — P1 · api, web
- **Welcome and away messages** — P1 · api, web
- **Data export on exit** — P1 · api, web, landing
- **Local payment methods** — P1 · api, web, landing
- **Monthly plans with an order bundle** — P0 · api, web, landing
- **Self-service changes** — P1 · api, web, landing
- **Starter plan for one-person sellers** — P1 · api, landing
- **Trial on a real number** — P1 · api, web, landing
- **Usage meter** — P0 · api, web, landing
- **Broadcast builder** — P1 · api, web
- **Cost estimate before send** — P1 · api, web
- **Frequency caps and quiet hours** — P1 · api, web
- **Opt-out handling** — P1 · api, web
- **Revenue attribution** — P1 · api, web
- **Store-aware segments** — P1 · api, web
- **Tracked links** — P1 · api, web
- **Chat buttons and QR codes** — P0 · api, web, landing
- **Budget caps and alerts** — P1 · api, web
- **Free allowance tracker** — P1 · api, web
- **Free-window indicator** — P1 · api, web
- **Live cost meter** — P0 · api, web
- **Meta billing setup** — P0 · api, web
- **Payment method status** — P1 · api, web
- **Messaging API** — P1 · api
- **Data protection: PDPL licence and cross-border transfer permit** — P0 · 
- **Legal documents: terms, privacy, DPA, AUP** — P0 · 

### M4 · Public launch · Mobile, landing & readiness

- **Infra: production environment and disaster recovery** — P0 · infra
- **Mobile: store listings and release pipeline** — P0 · mobile
- **Arabic help and support** — P1 · landing, web
- **Setup service** — P1 · web
- **Mobile: Drift local database and sync** — P1 · mobile, api
- **Mobile: push notifications (FCM / APNs)** — P0 · mobile, api
- **Shopify App Store listing, review and billing** — P0 · api, web
- **Landing: SEO and social** — P1 · landing
- **Landing: analytics, pixel and conversion tracking** — P1 · landing
- **Landing: contact sales and demo booking** — P1 · landing
- **Landing: feature pages** — P1 · landing
- **Landing: full home page** — P0 · landing
- **Landing: help center and docs site** — P1 · landing
- **Landing: how it works + onboarding preview** — P1 · landing
- **Landing: legal pages** — P0 · landing
- **Landing: performance and accessibility budget** — P1 · landing
- **Landing: pricing page** — P0 · landing
- **Customer support setup** — P1 · 
- **End-to-end test suite for critical journeys** — P1 · web, mobile
- **Go-live checklist and launch day** — P0 · 
- **Load and soak testing** — P0 · api, services
- **Pilot programme with 10–20 COD sellers** — P0 · 
- **Runbooks for launch** — P1 · infra
- **Security review and penetration test** — P0 · 
- **Arabic interface** — P1 · api, mobile
- **Full inbox** — P1 · api, mobile
- **Notification control** — P1 · api, mobile
- **Orders on mobile** — P1 · api, mobile
- **Quick replies and templates** — P1 · api, mobile
- **Meta Cloud API adapter and Embedded Signup under Trik's own Meta app** — P0 · api, services, web
- **Backups** — P1 · infra
- **Policy guardrails** — P1 · api, infra
- **Status page** — P1 · infra, landing

### M5 · Growth 1 · Deposits, couriers & true profit

- **Ad attribution** — P1 · api, web
- **Ad spend import** — P1 · api, web
- **Automation and AI results** — P1 · api, web
- **Daily digest** — P1 · api, web
- **Exports and scheduled reports** — P1 · api, web
- **Product costs** — P1 · api, web
- **Profit dashboards** — P1 · api, web
- **True profit per order** — P1 · api, web
- **Cheapest valid route** — P1 · api, web
- **Cost per order** — P1 · api, web
- **Reply merging** — P1 · api, web
- **Spend forecast** — P1 · api, web
- **Courier integrations** — P1 · api, web
- **Courier scorecard by zone** — P1 · api, web
- **Courier suggestion** — P1 · api, web
- **Meta ads and Conversions API** — P1 · api, web
- **Meta catalogue sync** — P1 · api, web
- **Payment gateways** — P1 · api, web
- **Salla and Zid** — P1 · api, web
- **Zapier, Make and n8n** — P1 · api, web
- **Landing: blog and resources** — P2 · landing
- **Landing: case studies from pilot sellers** — P2 · landing
- **Landing: public changelog page** — P2 · landing
- **Migrate MVP sellers from the BSP to Trik's Tech Provider** — P1 · api
- **Agent call queue** — P1 · api, web, mobile
- **Confirmation ladder** — P1 · api, web
- **Courier handoff** — P1 · api, web
- **Delivery rescue** — P1 · api, web
- **Deposit for risky orders** — P1 · api, web
- **Duplicate and fake order checks** — P1 · api, web
- **Manual deposit proof** — P1 · api, web
- **Prepaid conversion** — P1 · api, web
- **Re-order reminders** — P1 · api, web
- **Returns and exchanges** — P1 · api, web
- **Risk actions** — P1 · api, web
- **Stock check and back-in-stock** — P1 · api, web
- **Upsell at confirmation** — P1 · api, web
- **Cart and checkout** — P1 · api, web
- **Discounts** — P1 · api, web
- **One-tap re-order** — P1 · api, web
- **Payment links** — P1 · api, web
- **Product messages** — P1 · api, web
- **Receipt** — P1 · api, web
- **Stock-aware replies** — P1 · api, web

### M6 · Growth 2 · Automations, AI agent & campaigns

- **AI results** — P1 · api, web
- **Arabic dialects** — P1 · api, web
- **Guardrails** — P1 · api, web
- **Handoff with summary** — P1 · api, web
- **Knowledge training** — P1 · api, web
- **Order actions** — P1 · api, web
- **Product recommendations** — P1 · api, web
- **Review and correct** — P1 · api, web
- **Tone and persona** — P1 · api, web
- **Voice notes** — P1 · api, web
- **Actions** — P1 · api, web
- **Comment-to-message** — P1 · api, web
- **Commerce triggers** — P1 · api, web
- **Conversation triggers** — P1 · api, web
- **Flow analytics** — P1 · api, web
- **Human handoff** — P1 · api, web
- **In-chat forms** — P1 · api, web
- **Satisfaction survey** — P1 · api, web
- **Test mode** — P1 · api, web
- **Versions and rollback** — P1 · api, web
- **Visual flow builder** — P1 · api, web
- **A/B testing** — P1 · api, web
- **Click-to-WhatsApp launcher** — P1 · api, web
- **Journeys** — P1 · api, web
- **Quality protection** — P1 · api, web
- **Retargeting** — P1 · api, web
- **Rich formats** — P1 · api, web
- **SMS fallback** — P1 · api, web
- **Unique coupons** — P1 · api, web
- **AI assist** — P1 · api, web
- **Merge conversations** — P1 · api, web
- **Response-time targets** — P1 · api, web
- **Scheduled messages** — P1 · api, web
- **Translation** — P1 · api, web
- **Voice note transcription** — P1 · api, web, mobile

### M7 · Growth 3 · Teams, developers, Gulf & mobile

- **Branch routing** — P1 · api, web
- **Multiple workspaces** — P1 · api, web
- **Dark mode** — P1 · web, mobile
- **Gulf localisation** — P1 · api, web, mobile
- **Annual and partner discounts** — P1 · api, web, landing
- **Instagram and Facebook comments** — P1 · api, web, services
- **SMS** — P1 · api, web, services
- **Website chat** — P1 · api, web, services
- **Duplicate merge** — P1 · api, web
- **Lost reasons** — P1 · api, web
- **Sales pipeline** — P1 · api, web
- **Tasks and follow-up calendar** — P1 · api, web
- **API keys and scopes** — P1 · api, web
- **Developer tools** — P1 · api, web
- **Embeddable widgets** — P1 · api, web
- **Full REST API** — P1 · api, web
- **One-time code API** — P1 · api, web
- **Webhooks** — P1 · api, web
- **App lock** — P1 · api, mobile
- **Offline queue** — P1 · api, mobile
- **Owner dashboard** — P1 · api, web, mobile
- **Category advisor** — P1 · api, web
- **Multiple numbers** — P1 · api, web
- **Test number** — P1 · api, web
- **Verified badge help** — P1 · api, web
- **Data requests** — P1 · api, infra
- **Retention settings** — P1 · api, infra
- **Uptime commitment** — P1 · api, infra
- **Activity timeline** — P1 · api, web
- **Quality scorecards** — P1 · api, web
- **Supervisor view** — P1 · api, web
- **Targets and commission report** — P1 · api, web

### M8 · Scale

- **Partner commission** — P2 · api, web
- **Partner console** — P2 · api, web
- **Shared assets** — P2 · api, web
- **White label** — P2 · api, web
- **Conversation quality scoring** — P2 · api, web
- **Image understanding** — P2 · api, web
- **Voice agent** — P2 · api, web
- **Conversation insights** — P2 · api, web
- **Live wallboard** — P2 · api, web
- **Meta Ads spend connector** — P2 · api, web
- **Repeat purchase cohorts** — P2 · api, web
- **Appointment booking** — P2 · api, web
- **Approval workflow** — P2 · api, web
- **Email** — P2 · api, web, services
- **TikTok messages** — P2 · api, web, services
- **WhatsApp calling** — P2 · api, web, services
- **Deals and quotes** — P2 · api, web
- **Maximum-price sends** — P2 · api, web
- **AI assistant connector** — P2 · api, web
- **App marketplace** — P2 · api, web
- **Accounting export** — P2 · api, web
- **ERP and CRM connectors** — P2 · api, web
- **Network courier averages** — P2 · api, web
- **Scan to find** — P2 · api, mobile
- **Automated voice confirmation** — P2 · api, web
- **Cash reconciliation** — P2 · api, web
- **Shared risk network** — P2 · api, web
- **Regional hosting** — P2 · api, infra
- **Mini storefront** — P2 · api, web, landing
- **Single sign-on and IP rules** — P2 · api, web
