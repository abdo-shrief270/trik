# services — Go services on the hot paths

The Laravel app handles business logic. These small Go services handle the paths that see the most traffic,
where latency and throughput matter most:

| Service | Hot path |
|---|---|
| [`webhook-intake`](webhook-intake) | Every inbound webhook from Meta, stores and couriers |
| [`message-writer`](message-writer) | Writing every message to PostgreSQL |
| [`dispatcher`](dispatcher) | Sending every outbound message within Meta's limits |

They communicate only through Redpanda topics defined in [`packages/contracts/events`](../packages/contracts/events).
