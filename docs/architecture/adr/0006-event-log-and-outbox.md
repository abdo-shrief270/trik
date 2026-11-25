# ADR 0006: Redpanda event log with a transactional outbox

- **Status:** Accepted
- **Date:** 2026-11-25

## Context

Modules and services must react to changes reliably without distributed transactions.

## Decision

Every cross-module change writes an outbox row in the same database transaction. A relay publishes it to Redpanda. Consumers are idempotent on `event_id`.

## Consequences

No lost or double-applied events. Small delay between write and publish.
