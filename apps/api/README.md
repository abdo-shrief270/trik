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
automatically; migrations in `database/migrations` and routes in `src/Http/routes.php` (mounted under `/api/v1`) load by convention.

## Workspaces and row-level security

Two database connections:

| Connection | Runs as | Used for |
|---|---|---|
| `pgsql` (default) | `trik_app`, set with `SET ROLE` on connect (`DB_ROLE`) | everything the app does |
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
- PgBouncer must run in **session** pooling mode while the setting is session-level (switch to `SET LOCAL` per transaction before moving to transaction pooling).

## Sign-in

| Client | How |
|---|---|
| Web app (a Sanctum stateful domain, `SANCTUM_STATEFUL_DOMAINS`) | `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login` → session cookie |
| Mobile / API | `POST /api/v1/auth/login` with `device_name` → bearer token |

Endpoints under `/api/v1/auth`: `register` (creates the user and their first workspace), `login`, `logout`, `me`,
`forgot-password`, `reset-password`, `email/verify/{id}/{hash}`, `email/verification-notification`,
`sessions` (list devices) and `DELETE sessions/{token|browser}/{id}` (sign a device out).
Five wrong passwords lock that email + IP for a minute.

## Modules

See [`modules/README.md`](modules/README.md).
