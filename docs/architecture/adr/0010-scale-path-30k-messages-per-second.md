# ADR 0010: Scale path to a 30,000 messages/second peak

- **Status:** Accepted
- **Date:** 2026-11-26

## Context

Trik must handle campaign bursts of **30,000 outbound messages per second** (peak, not sustained) at the lowest infrastructure cost.

What that load really is:

- **Statuses outnumber sends.** Every send comes back as about three status webhooks (sent, delivered, read). A 30k/s send peak means **~90–100k inbound webhook events/s**. Webhook intake is the hardest path.
- **Meta limits each number** to 80 messages/s by default and up to 1,000/s after an upgrade, and also limits messages to the same user. 30k/s is a platform total over many numbers, so sending is a per-number scheduling problem.
- **The volume is mostly campaigns.** Broadcasts are the bulk; inbox conversations are a small share and human-paced.
- PostgreSQL handles batched inserts well. It does not like ~90k single-row `UPDATE`s/s (bloat, WAL, locks).

## Decision

### 1. Target architecture (when traffic needs it)

```
Meta / BSP ─► webhook-intake (Go) ─► Redpanda ─► message-writer (Go) ─► PostgreSQL  (conversations)
               verify, append, 200      │          batches, COPY          ClickHouse  (campaign status facts)
               no database               ▼
                               Laravel (control plane: API, UI, business rules, low-volume events)
Campaigns ─► dispatcher (Go): per-number token buckets, pair limits, retries, HTTP/2 pools ─► Meta
```

1. **Intake does almost nothing**: verify the signature, append to Redpanda, answer 200. No database on the hot path. Target: ack p99 < 50 ms.
2. **Writes are batched**: thousands of rows per statement, never one per event.
3. **Data is split by how it is used**:
   - conversation messages (inbox) live in PostgreSQL;
   - per-message campaign statuses go to ClickHouse as facts, and PostgreSQL keeps counters per broadcast.
4. **High-volume tables are partitioned by month from day one.** Old data is dropped a partition at a time.
5. **Laravel stays the control plane.** It never carries the 30k/s; it receives aggregated or low-volume events.
6. **The Go services use their own system role and pass `workspace_id` explicitly.** Row-level security keeps protecting the Laravel path.
7. **Laravel keeps session-level tenant context** (Octane holds a small number of persistent connections, so no transaction pooling for Laravel). A per-transaction `SET LOCAL` mode was considered and rejected: it would force every request into a transaction for no gain at Laravel's volume.

### 2. Staged rollout. Each stage is triggered by measured load, not by the calendar.

| Stage | Load | What runs |
|---|---|---|
| Pilot | up to a few hundred msg/s | Laravel + Redis queues on one server (#343, #346) |
| Launch | above ~1–2k webhook events/s sustained, or when the pilot server's queue lag exceeds 5 s | add Go `webhook-intake` + Redpanda (#95, #29) |
| Scale | above ~10k events/s, or the first 30k/s campaign | add Go `message-writer` + `dispatcher` + ClickHouse status facts (#96, #97) |

### 3. Decisions taken now so later stages need no rewrite

- Provider contract and per-number provider (ADR 0009, #329).
- The raw delivery is stored or appended **before** acknowledging. It is idempotent per provider message id.
- Events go through `EventPublisher` (Laravel events now, outbox → Redpanda later, ADR 0006).
- UUIDv7 ids: time-ordered and partition-friendly.
- Monthly partitions on `whatsapp_webhook_events`, `whatsapp_messages` and `whatsapp_broadcast_sends`, with daily `whatsapp:maintain`.
- Campaign sends live in `whatsapp_broadcast_sends`, apart from conversation messages. Progress is published **per broadcast per batch**, not per message.
- Statuses are applied **in batches**: deliveries are drained 500 at a time with `SKIP LOCKED`, and each batch costs a fixed number of queries (one `UPDATE … FROM (VALUES …)` per table, `RETURNING old/new`).
- A delivery that keeps failing is isolated and parked after 5 attempts. It never blocks the rest.
- Load tests with k6 (`tests/load/`) prove each stage before we rely on it.

## Consequences

- The pilot stays simple and cheap. The scale path is known and incremental.
- More moving parts at the Scale stage (Redpanda, Go services, ClickHouse), each with a clear job.
- Per-message campaign status is not kept in PostgreSQL at Scale; reports read ClickHouse.
- Partition maintenance is a daily job, and a default partition with rows raises an alert.
