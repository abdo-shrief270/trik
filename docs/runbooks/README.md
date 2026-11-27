# Runbooks

One file per scenario. Each runbook says: how you notice, how bad it is, what to do step by step, how to confirm it is fixed.

- [`deploy.md`](deploy.md): first production setup, releases, rollback
- [`edge-proxy.md`](edge-proxy.md): client IPs and trusted proxies behind Cloudflare
- [`database-roles.md`](database-roles.md): the three PostgreSQL roles and where each is used

Planned:
- `meta-webhook-backlog.md`: inbound webhooks piling up in Redpanda
- `dispatcher-rate-limited.md`: Meta throttling a number or the account
- `database-failover.md`
- `courier-api-down.md`
- `incident-template.md`
