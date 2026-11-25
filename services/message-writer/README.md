# services/message-writer

Consumes message events from Redpanda and writes them to PostgreSQL in batches. Keeps ordering per conversation and is idempotent on the message ID.

## Layout

```
cmd/message-writer/main.go       # Entry point: config, wiring, graceful shutdown
internal/
├── config/              # Environment configuration
├── transport/           # HTTP handlers or Kafka consumers
├── app/                 # Business logic
└── platform/            # Kafka, PostgreSQL, Redis, telemetry clients
```

## Run

```bash
go run ./cmd/message-writer
go test -race ./...
```

Exposes `/healthz`, `/readyz` and Prometheus metrics on `/metrics`.
