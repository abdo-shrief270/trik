# packages/contracts — the source of truth for APIs and events

```
openapi/trik.v1.yaml       # Public and app-facing REST API (OpenAPI 3.1)
events/                    # Event payloads on Redpanda (JSON Schema 2020-12)
```

## Rules

- Change the contract **first**, in its own commit, then the code.
- Events are versioned in the name (`order.confirmed.v1`). A breaking change is a new version; old versions stay until no consumer uses them.
- Every event carries `event_id` (UUIDv7), `occurred_at`, `workspace_id` and `schema`. Consumers deduplicate on `event_id`.
- Topic names: `<module>.<entity>.v<n>`, for example `orders.order.v1`.
