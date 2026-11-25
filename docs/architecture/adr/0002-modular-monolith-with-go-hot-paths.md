# ADR 0002: Modular monolith in Laravel, Go on the hot paths

- **Status:** Accepted
- **Date:** 2026-11-25

## Context

A small team must ship fast, but webhook intake, message writing and sending see the highest load and strict latency.

## Decision

The core is one Laravel 12 app on Octane (FrankenPHP) split into modules with enforced boundaries. Three Go services handle webhook intake, message writing and dispatch. They communicate through Redpanda.

## Consequences

One codebase for business logic; hot paths scale on their own. Module boundaries keep extraction possible.
