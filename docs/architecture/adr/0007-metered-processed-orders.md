# ADR 0007: Plans include a bundle of processed orders

- **Status:** Accepted
- **Date:** 2026-11-25

## Context

Pricing per confirmed order invites sellers to confirm outside the platform.

## Decision

Plans are monthly with a bundle of processed orders (orders that enter Trik), counted idempotently by order ID.

## Consequences

Fair, predictable pricing that cannot be gamed by confirming elsewhere.
