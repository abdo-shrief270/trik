# apps/api — Trik core platform

Laravel 13 on PHP 8.5, served by **Octane with FrankenPHP**. Built as a **modular monolith**:
one Laravel app, split into modules with hard boundaries, so each module can be extracted later if it needs to scale on its own.

## Run it locally

```bash
make up                                   # PostgreSQL, Redis, Mailpit… from the repo root
cd apps/api
cp .env.example .env && php artisan key:generate
composer install
composer migrate                          # = php artisan migrate --database=owner
php artisan octane:start --port=8080      # or: php artisan serve --port=8080
```

Tests need a PostgreSQL database called `trik_test` (user `trik` / `trik`, see `phpunit.xml`):

```bash
composer test     # Pest: architecture, unit and feature tests
composer lint     # Pint + PHPStan (level 8)
```

Health checks: `GET /healthz` (process is up) and `GET /readyz` (database and cache reachable).

## Layout

```
apps/api/
├── app/                     # Laravel glue only: providers, base HTTP kernel, shared middleware
├── modules/                 # ← all business code lives here, one folder per module
│   └── Orders/
│       ├── src/
│       │   ├── Domain/          # Entities, value objects, domain events. No framework code.
│       │   ├── Application/     # Use cases (commands, queries, handlers), ports (interfaces)
│       │   ├── Infrastructure/  # Eloquent models, repositories, external clients, listeners
│       │   └── Http/            # Controllers, requests, resources, routes
│       ├── database/migrations/
│       ├── tests/{Unit,Feature}/
│       └── README.md            # Purpose, public API, events it emits and listens to
├── config/
├── routes/                  # Only loads module routes
└── tests/Architecture/      # Pest architecture tests that enforce module boundaries
```

## Module rules

1. A module talks to another module **only** through that module's `Application` contracts or through **domain events**. Never through its models or tables.
2. Every table belongs to exactly one module and is prefixed with it (`orders_`, `risk_`, …).
3. Cross-module side effects go through the **outbox**: the event is stored in the same transaction, then published to Redpanda.
4. Every handler that consumes an event is **idempotent** (keyed on the event ID).
5. Every row that belongs to a seller carries `workspace_id`. Queries are scoped by a global scope plus PostgreSQL row-level security (see below).
6. Architecture tests in `tests/Architecture` fail the build when a rule is broken.

Create a new module with `make module name=Returns`. It gets a `ReturnsServiceProvider`, which is registered
automatically; migrations in `database/migrations` and routes in `src/Http/routes.php` (mounted under `/v1`) load by convention.

## Workspaces and row-level security

Two database connections:

| Connection | Runs as | Used for |
|---|---|---|
| `pgsql` (default) | `trik_app`, set at connect time with `-c role=…` (`DB_ROLE`) | everything the app does |
| `owner` | the table owner | migrations and cross-workspace system work only |

How a tenant table is protected:

```php
// in the migration
Schema::create('orders', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('workspace_id')->constrained('workspaces');
    // …
});
RowLevelSecurity::enable('orders');

// on the model
final class Order extends Model
{
    use BelongsToWorkspace;   // filters reads, stamps writes, throws without a workspace
}
```

- The `workspace` route middleware reads `X-Workspace-Id`, checks membership and activates the workspace for the request.
- The active workspace is written to the PostgreSQL setting `app.workspace_id`; the policy only matches rows with that id.
  With no workspace active, Eloquent throws `MissingWorkspaceContext` and raw SQL sees no rows: it **fails closed**.
- Queued jobs carry the workspace in their payload and run inside it.
- A test fails the build if any table with a `workspace_id` column does not have row-level security on.
- `/readyz` fails if the app connection is not running as `DB_ROLE`, so a pooler that drops the role option can't silently turn RLS off.
- PgBouncer must run in **session** pooling mode while the setting is session-level (switch to `SET LOCAL` per transaction before moving to transaction pooling).

## API conventions (#30)

The contract is [`packages/contracts/openapi/trik.v1.yaml`](../../packages/contracts/openapi/trik.v1.yaml). Change it first;
`tests/Feature/OpenApiCoverageTest.php` fails if a `/v1` route or its permission is missing there.

| Topic | Rule |
|---|---|
| Base URL | `https://api.trik.chat/v1` (breaking changes → `/v2`; additive changes stay in v1) |
| Workspace | `X-Workspace-Id` header on workspace routes |
| Errors | RFC 9457 `application/problem+json`: `type`, `title`, `status`, `detail`, a stable `code` clients switch on, `request_id`; validation adds `errors` per field |
| Lists | cursor pagination: `?per_page=25&cursor=…` → `{ data, meta: { per_page, next_cursor, prev_cursor } }` |
| Retries | `Idempotency-Key` on any write replays the first response for 24 h; required where the contract says so (sending messages, confirming orders) |
| Tracing | every response carries `X-Request-Id` (kept from the edge if set) and logs include it |

## Roles and permissions (#28)

- Each module declares its permissions in its provider (`permissions()`): `whatsapp.messages.send`, `workspace.members.manage`, …
- Built-in roles live in code: **owner** (`*`), **admin** (`*` except deleting the workspace and billing), **agent** (inbox work).
  Workspaces can add **custom roles** from the catalogue (`GET /v1/workspace/permissions/catalog`). Grants support `module.*` and `!deny`.
- Protect a route with `->middleware('can:whatsapp.numbers.manage')`, or call `Gate::authorize(...)`. A registered permission is checked against the member's grants in the active workspace.
- Clients read `GET /v1/workspace/permissions` to show or hide actions; the server always re-checks.
- Team rules: nobody changes their own role, only owners make or unmake owners, a workspace always keeps an owner.

## Sign-in

| Client | How |
|---|---|
| Web app (a Sanctum stateful domain, `SANCTUM_STATEFUL_DOMAINS`) | `GET /sanctum/csrf-cookie`, then `POST /v1/auth/login` → session cookie |
| Mobile / API | `POST /v1/auth/login` with `device_name` → bearer token |

Endpoints under `/v1/auth`: `register` (creates the user and their first workspace), `login`, `logout`, `me`,
`forgot-password`, `reset-password`, `email/verify/{id}/{hash}`, `email/verification-notification`,
`sessions` (list devices) and `DELETE sessions/{token|browser}/{id}` (sign a device out).
Five wrong passwords lock that email + IP for a minute.

## Modules

See [`modules/README.md`](modules/README.md).
