# ADR 0009: Run the MVP on a WhatsApp BSP until Trik is a Tech Provider

- **Status:** Proposed
- **Date:** 2026-11-26

## Context

Becoming a Meta Tech Provider (business verification, app review, access verification) takes weeks. We want pilot sellers on the product before that.

## Decision

The MVP connects sellers' numbers through a Business Solution Provider's partner programme. All WhatsApp access goes through a `WhatsAppProvider` interface with one adapter per provider and a provider setting per phone number. After Tech Provider approval, new sellers use Trik's own Meta app and the Cloud API, and pilot numbers are migrated (issues #328, #329, #330, #336, #338).

The BSP is chosen on: onboarding of client numbers through a partner API, coexistence support, Meta fees at Meta's rate (paid by the seller where possible), per-number fee, and a written migration-out path.

## Consequences

Faster start and less Meta paperwork up front. A per-number BSP fee during the MVP, and one planned migration. ADR 0003 (Meta bills sellers directly) stays the target state.
