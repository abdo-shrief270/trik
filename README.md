# Trik

**The third tick.** WhatsApp-first order operations for sellers in Egypt and the Middle East.
Every chat becomes a confirmed, delivered and profitable order.

- Website: `trik.chat`
- Status: pre-launch, building the **Launch** phase (105 features)

---

## Repository map

| Path | What lives there | Stack |
|---|---|---|
| `apps/api` | Core platform: a modular monolith with one module per business area | Laravel 13, PHP 8.5, Octane (FrankenPHP) |
| `apps/web` | Seller web app: inbox, confirmations, orders, analytics, settings | Nuxt 4.7, Vue 3.5, TypeScript 5.9 |
| `apps/mobile` | Owner and agent mobile apps (iOS and Android) | Flutter 3.50, Drift (offline store) |
| `services/webhook-intake` | Receives Meta and store webhooks, verifies, writes to the event log | Go |
| `services/message-writer` | Persists inbound and outbound messages at high volume | Go |
| `services/dispatcher` | Sends outbound messages within Meta rate limits, retries with backoff | Go |
| `packages/contracts` | API (OpenAPI) and event (JSON Schema) contracts shared by every app | YAML, JSON Schema |
| `packages/design-tokens` | Trik colours, type and spacing as tokens for web and mobile | JSON, CSS |
| `infra` | Local stack config, Kubernetes, Terraform, observability | Docker, K8s, Terraform |
| `docs` | Product spec, architecture, ADRs, legal, brand book, runbooks | Markdown, PDF |
| `design` | Logo files, screen designs and exports | SVG, PNG |
| `scripts` | Developer tooling: bootstrap, module generator | Bash, PowerShell |

## Platform at a glance

```
Meta / Stores / Couriers ──► webhook-intake (Go) ──► Redpanda (event log)
                                                        │
                    ┌───────────────────────────────────┼───────────────────────────┐
                    ▼                                   ▼                           ▼
             message-writer (Go)                 apps/api (Laravel)          ClickHouse (analytics)
                    │                         modules · outbox · jobs
                    ▼                                   │
             PostgreSQL 19 ◄── PgBouncer ──────────────┘──► Redis (cache · queues · locks)
                                                        │
                    Centrifugo (real-time) ◄────────────┘──► dispatcher (Go) ──► Meta Cloud API
                                                        │
                                    apps/web (Nuxt) · apps/mobile (Flutter)
```

Full detail: [`docs/architecture`](docs/architecture). Decisions: [`docs/architecture/adr`](docs/architecture/adr).

## Getting started

### Prerequisites

| Tool | Version |
|---|---|
| Docker Desktop (with Compose v2) | latest |
| PHP + Composer | PHP 8.5, Composer 2 |
| Node.js + pnpm | Node 26 LTS, pnpm 12 |
| Go | 1.27 |
| Flutter | 3.50 (stable) |
| Make | GNU Make (Windows: use `scripts/bootstrap.ps1` or WSL) |

Check everything with `./scripts/bootstrap.sh` (macOS, Linux, WSL) or `scripts\bootstrap.ps1` (Windows PowerShell).

### Run the local stack

```bash
cp .env.example .env          # local-only passwords and ports
make up                       # PostgreSQL 19, PgBouncer, Redis x3, Redpanda, ClickHouse, Meilisearch, Centrifugo, Mailpit, MinIO
make ps                       # check every service is healthy
```

Local service URLs (default ports):

| Service | URL |
|---|---|
| Redpanda Console | http://localhost:8085 |
| Mailpit (test inbox) | http://localhost:8025 |
| MinIO console | http://localhost:9001 |
| Meilisearch | http://localhost:7700 |
| Centrifugo admin | http://localhost:8000 |
| ClickHouse HTTP | http://localhost:8123 |

### Create the apps (first time only)

Each app folder has its own README with the exact commands. The short version:

```bash
make scaffold-api      # Laravel 13 into apps/api
make scaffold-web      # Nuxt 4 into apps/web
make scaffold-mobile   # Flutter into apps/mobile
```

## Working agreements

- **Branches:** trunk-based. Short-lived branches from `main`, named `feat/…`, `fix/…`, `chore/…`, `docs/…`.
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org) (`feat(orders): add confirmation ladder`).
- **Pull requests:** one change per PR, CI green, one approval, squash merge.
- **Decisions:** anything hard to reverse gets an ADR in `docs/architecture/adr`.
- **Contracts first:** change `packages/contracts` before changing an API or event.
- **Secrets:** never in the repo. `.env` files are ignored; production secrets live in the secret manager.

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## Product phases

| Phase | Features | Goal |
|---|---|---|
| Launch | 105 | Enough to charge for: inbox, confirmation engine, risk score, store connectors |
| Growth | 107 | Hard to leave: deposits, courier scorecard, true profit, AI agent, flows |
| Scale | 30 | Bigger accounts and new markets: voice confirmation, partners, regional hosting |

The full list is in [`docs/product`](docs/product).

---

© 2026 Trik. All rights reserved. Proprietary and confidential.
