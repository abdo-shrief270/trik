# CLAUDE.md — working on Trik

Trik (trik.chat) is a WhatsApp-first COD commerce and order-operations SaaS for Egyptian / MENA sellers:
a shared inbox, order confirmation over WhatsApp, refusal risk, store connectors (Shopify, EasyOrders),
campaigns and billing. Owner: Abdo (abdo-shrief270), backend developer, Cairo. He writes in English or
Arabic; answer in English unless he writes Arabic. He prefers complete, polished PRs over small partial
ones, and expects security, load and edge cases checked before he merges.

## Read first

| What | Where |
|---|---|
| Product plan, milestones, every feature as an issue | `docs/plan/PLAN.md` (GitHub issues are generated from it) |
| Architecture decisions | `docs/architecture/adr/` (0010 scale path, 0011 Hostinger first, 0012 organizations) |
| Security review and its fixes | `docs/security/2026-11-review.md` |
| HTTP API contract (source of truth) | `packages/contracts/openapi/trik.v1.yaml` |
| Event contracts | `packages/contracts/events/*.schema.json` |
| Each backend module | `apps/api/modules/<Name>/README.md` |

## Stack

- **API:** Laravel 13 on PHP 8.5, Octane (FrankenPHP in prod, Swoole locally/CI), Sanctum, Pest, Larastan
  level 8, Pint. PostgreSQL 19, Redis 8. `apps/api`.
- **Web:** Nuxt 4 (`apps/web`). **Mobile:** Flutter (`apps/mobile`). **Go services** for hot paths later (`services/`).
- **Hosting:** Hostinger VPS first, AWS later (ADR 0011). WhatsApp through a BSP (360dialog) first (ADR 0009).

## Backend architecture (apps/api)

- **Modular monolith:** `modules/<Name>/src/{Domain,Application,Infrastructure,Http}`, plus `database/migrations`,
  `tests/{Unit,Feature}` and a README. `Modules\<Name>\<Name>ServiceProvider` (extends `ModuleServiceProvider`)
  is found automatically and loads migrations, `src/Http/routes.php` under `/v1`, and `src/Http/webhooks.php`
  under `/webhooks`. Create one with `scripts/new-module.sh <Name>`. Keep empty folders with a `.gitkeep`:
  the layout test needs them.
- **Module boundaries (architecture tests enforce them):**
  - `Domain` has no framework code.
  - A module never uses another module's `Infrastructure` (models). Cross-module access goes through the other
    module's `Application` classes (readers, presenters, DTOs) or `app/Support` contracts.
- **Tenancy (never weaken):**
  - The app connects as the restricted role `trik_app` (`server_options` role). Every tenant table has
    `workspace_id` and `RowLevelSecurity::enable()`: reads match `app.workspace_ids`, writes match `app.workspace_id`.
  - Models use `BelongsToWorkspace`. `TenantContext::run($ws, fn)` for one workspace; `runReadOnly([...])` across
    the user's workspaces (organization views, badges).
  - Jobs carry the workspace (`QueueTenancy`). Cross-workspace system work uses the `owner` connection (ids only),
    then runs each item inside its workspace.
  - Webhook routing uses narrow `SECURITY DEFINER` functions.
- **API conventions:**
  - Errors are RFC 9457 problem+json via `App\Support\Http\Problem` (stable `code`). 4xx problems are not logged.
  - Lists use cursor pagination (`CursorPage`, with validated cursors).
  - Writes that send or create take `idempotency:required`.
  - **Change `trik.v1.yaml` first.** `OpenApiCoverageTest` fails if a route or its `can:` permission is missing.
    Edit the YAML with a PyYAML load/dump script (no-alias dumper, keep the first comment line), then validate
    with `openapi-spec-validator`.
- **Permissions:**
  - Each module declares `permissions()`; routes use `can:<module>.<thing>.<action>`.
  - Built-in roles are in `Modules\Workspaces\Domain\WorkspaceRole` (update the agent list and `PermissionsTest`
    when adding permissions).
  - Grant ceiling: nobody grants more than they have.
  - Organization permissions are a separate catalogue (`X-Organization-Id`).
- **Events:**
  - `EventPublisher::publish()` runs after commit (the outbox later, #29).
  - In-transaction, set-based listeners are allowed only where documented (e.g. `WhatsApp\MessagesLogged` → inbox).
  - Real time goes through `App\Support\Realtime\Realtime` (Centrifugo, best effort, after commit): `inbox:{workspace}`
    and `user:{id}` channels.
- **Feature flags:** `feature:<name>` middleware, config `trik.features.*` (`all` | `none` | workspace ids).

## Pitfalls already hit (don't reintroduce)

- **Octane:** a runtime callback (`Gate::before`, rate limiters…) must resolve services with `app()`, never a
  provider's `$this->app` (it is the boot container: per-request scoped state is invisible). An arch test and the
  CI Octane probe step guard this.
- **Time zones:** both DB connections set `'timezone' => 'UTC'`. Laravel writes timestamps without an offset; a
  non-UTC session shifts them. CI's Postgres is UTC and won't show it.
- **jsonb** returns keys in its own order: compare decoded arrays as values (`==`), not as JSON strings or `===`.
- **Generated columns** (e.g. `orders_orders.stage`) are stale on a saved model: compute in PHP with the same rule
  (`BoardStage::of`) and keep a test that both agree.
- **Mail and other slow I/O never run inside a request:** notifications are queued (`afterCommit`).
- `migrate:fresh` does not drop functions: use `create or replace function` in migrations.
- Laravel test client keeps the first authenticated user: call `app('auth')->forgetGuards()` before switching
  users (`inboxAs()` in `tests/Helpers.php` does this). Shared test helpers live in `tests/Helpers.php`.
- A second DB connection in a test can't see the test's uncommitted data (and can deadlock on it).

## Running things

```bash
# services (local or cloud workspace): PostgreSQL 19 + Redis 8
#   docker compose up -d postgres redis   (or a local cluster; this repo's past sessions used port 5433)
cd apps/api
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --database=owner            # migrations always on the owner connection
vendor/bin/pint && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest
# security probes against the app under Octane (CI runs these too):
php artisan octane:start --server=swoole --port=8099 &
python3 ../../tests/security/api_probe.py http://127.0.0.1:8099 --db "host=127.0.0.1 port=5432 user=trik password=trik dbname=<db>"
```

Before every PR: Pint, PHPStan (level 8, no ignores), the full Pest suite, a valid OpenAPI file, and the probe
script under Octane. Add probes for every new endpoint (IDOR from another workspace, permission escalation,
forged fields). Measure hot paths when you touch them (the webhook drain was ~3,000 deliveries/s per worker).

## Git and PRs

- Branch from `main` (or stack on the open PR it depends on, and say so in the PR). Never push to `main`.
- Delete `dump.rdb` and `apps/api/dump.rdb` before committing (Redis writes them; they are git-ignored, but check).
- GitHub CLI: use `gh api` REST calls only (GraphQL is blocked in Claude sessions). Open PRs with
  `gh api repos/abdo-shrief270/trik/pulls -f title=… -f head=… -f base=… -F body=@file`.
- PR body: what it adds, bugs found, checks run; "API part of #N" when web/mobile tasks of the issue remain, "Closes #N"
  only when the issue is fully done. Comment on the issue with what landed.
- Wait for CI (`gh api repos/…/commits/<sha>/check-runs`) and fix failures before reporting done.

## Where things stand (update this section when it changes)

Done and merged on `main`:
- foundation (#24–#30);
- the WhatsApp provider layer (360dialog / Meta Cloud / Fake, partitioned storage, batch webhook drain);
- permissions and roles;
- ADRs 0010–0012 and the security review;
- organizations above workspaces (#366), and organization roles, people and audit logs (#367).

Open PRs, stacked; merge in this order (each retargets to `main` by itself):
1. #379 inbox: one conversation per customer + 24 h window (#56, #60)
2. #380 per-workspace badges for the switcher (#368)
3. #381 Orders module + order card in chat + address fix (#105, #344)
4. #382 confirmation engine over WhatsApp (#106)
5. #383 confirmation board with bulk actions (#107)

If they're still open when you start, branch from `feat/confirmation-board` (the top of the stack) and target it.

Next, in order (milestone "MVP · Pilot" first, see `docs/plan/PLAN.md`):
- **Shopify app (#332).** OAuth install, encrypted tokens, GraphQL Admin client with cost-based limits, HMAC
  webhooks (orders/create, orders/updated, fulfillments, app/uninstalled), 90-day backfill. Orders go through
  `Orders\Application\RecordOrder` (idempotent per source + external id). Then mandatory privacy webhooks (#333)
  and status / address write-back.
- **EasyOrders (#117)**, the same shape.
- **Refusal risk score (#115)**: fills `orders_orders.risk_score`, which the board already filters on.
- **Pilot results (#345), web app screens (#34–#37), infra tasks (#346, #350, #352, #360–#362).**
