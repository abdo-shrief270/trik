# services/dispatcher

Sends outbound WhatsApp messages. Respects Meta throughput and per-number limits, retries with exponential backoff starting at one second, and reports delivery status back as events.

## Layout

```
cmd/dispatcher/main.go       # Entry point: config, wiring, graceful shutdown
internal/
├── config/              # Environment configuration
├── transport/           # HTTP handlers or Kafka consumers
├── app/                 # Business logic
└── platform/            # Kafka, PostgreSQL, Redis, telemetry clients
```

## Run

```bash
go run ./cmd/dispatcher
go test -race ./...
```

Exposes `/healthz`, `/readyz` and Prometheus metrics on `/metrics`.
