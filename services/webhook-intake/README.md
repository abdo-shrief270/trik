# services/webhook-intake

Receives webhooks from Meta, stores and couriers. Verifies signatures, deduplicates, writes each event to Redpanda and answers within Meta's time limit. Never calls the database directly.

## Layout

```
cmd/webhook-intake/main.go       # Entry point: config, wiring, graceful shutdown
internal/
├── config/              # Environment configuration
├── transport/           # HTTP handlers or Kafka consumers
├── app/                 # Business logic
└── platform/            # Kafka, PostgreSQL, Redis, telemetry clients
```

## Run

```bash
go run ./cmd/webhook-intake
go test -race ./...
```

Exposes `/healthz`, `/readyz` and Prometheus metrics on `/metrics`.
