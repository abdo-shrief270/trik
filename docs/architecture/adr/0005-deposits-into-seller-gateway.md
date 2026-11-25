# ADR 0005: Deposits go into the seller's own gateway account

- **Status:** Accepted
- **Date:** 2026-11-25

## Context

Risky COD orders need a deposit. Holding customer money would make Trik a payment facilitator.

## Decision

Payment links are created on the seller's own Paymob, Kashier or Fawry account. Trik never receives or holds funds; it only listens for payment events.

## Consequences

No payment licence needed. Sellers must connect a gateway to use deposits.
