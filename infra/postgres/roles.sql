-- Production database roles (ADR 0011, docs/security/2026-11-review.md F-14).
-- Run once as a superuser when the database is created, e.g. from Ansible:
--   psql -v owner_password="$OWNER_PW" -v api_password="$API_PW" -d trik -f infra/postgres/roles.sql
--
--   trik_owner  owns the schema and tables. Used ONLY by migrations and the scheduler
--               (whatsapp:maintain, whatsapp:sweep). Its password never reaches API or worker hosts.
--   trik_app    no login. Has data access to app tables; row-level security applies to it.
--   trik_api    the login the API and queue workers use. Owns nothing and has no privileges of
--               its own (NOINHERIT): it can only act as trik_app. If the app is compromised,
--               RESET ROLE leaves an attacker with no access at all, so RLS can't be switched off.

\set ON_ERROR_STOP on

create role trik_owner login password :'owner_password' nosuperuser nocreatedb nocreaterole nobypassrls;
create role trik_app nologin nosuperuser nobypassrls;
create role trik_api login password :'api_password' noinherit nosuperuser nocreatedb nocreaterole nobypassrls connection limit 200;

grant trik_app to trik_owner with admin option;   -- the first migration re-grants and sets default privileges
grant trik_app to trik_api;                       -- trik_api may SET ROLE trik_app, nothing else

alter database trik owner to trik_owner;
revoke all on database trik from public;
grant connect on database trik to trik_api;
alter schema public owner to trik_owner;
revoke create on schema public from public;

-- Defaults that limit damage from a runaway query or a stuck transaction
alter role trik_api set statement_timeout = '15s';
alter role trik_api set idle_in_transaction_session_timeout = '30s';
alter role trik_api set lock_timeout = '5s';
