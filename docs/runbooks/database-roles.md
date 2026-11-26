# Database roles and credentials

| Role | Login | Used by | Can do |
|---|---|---|---|
| `trik_owner` | yes | migrations (deploy job), scheduler (`whatsapp:maintain`, `whatsapp:sweep`) | owns tables; bypasses RLS as owner |
| `trik_app` | no | — (a role others become) | read/write app tables, **subject to RLS** |
| `trik_api` | yes | API and queue workers | only `SET ROLE trik_app`; nothing on its own |

Created by [`infra/postgres/roles.sql`](../../infra/postgres/roles.sql).

## Environment per host

| Host | `DB_USERNAME` | `DB_ROLE` | `DB_OWNER_USERNAME` |
|---|---|---|---|
| API, workers | `trik_api` | `trik_app` | **unset** (the `owner` connection then has no access) |
| Scheduler, deploy job | `trik_api` | `trik_app` | `trik_owner` |

Rules:

- The owner password lives only in the deploy secret store and on the scheduler host.
- `trik_api` has `statement_timeout = 15s`, `idle_in_transaction_session_timeout = 30s` and `lock_timeout = 5s`.
- Local development uses one superuser (`trik`) for convenience. That is never the production setup.
- `/readyz` fails if the API connection is not running as `trik_app`.

## Checking a server

```sql
select rolname, rolsuper, rolbypassrls, rolinherit from pg_roles where rolname like 'trik_%';
-- as trik_api, after `reset role`, this must fail with "permission denied":
select count(*) from whatsapp_messages;
```
