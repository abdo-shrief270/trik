# apps/api — Trik core platform

Laravel 13 on PHP 8.5, served by **Octane with FrankenPHP**. Built as a **modular monolith**:
one Laravel app, split into modules with hard boundaries, so each module can be extracted later if it needs to scale on its own.

## Create the app (first time only)

```bash
make scaffold-api          # or: ./scripts/scaffold-api.sh
```

This runs `composer create-project laravel/laravel` into a temporary folder and moves it here,
keeping the `modules/` folder and this README.

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
5. Every row that belongs to a seller carries `workspace_id`. Queries are scoped by a global scope plus PostgreSQL row-level security.
6. Architecture tests in `tests/Architecture` fail the build when a rule is broken.

Create a new module with `make module name=Returns`.

## Modules

See [`modules/README.md`](modules/README.md).
