# ADR 0003: Inventory and finance ledgers

## Status
Accepted

## Context
Stock levels and financial balances derived only from mutable “current” columns drift under concurrent syncs, refunds, and adjustments.

## Decision
Treat **inventory** and **finance** as append-only ledgers (movements/entries). Current stock and balances are projections derived from ledger entries (materialized caches allowed, rebuilt from the ledger). Corrections are compensating entries, not in-place edits of history.

## Consequences
- Auditable trail for stock and money.
- Reconcile/rebuild jobs become first-class.
- Slightly more write volume and query complexity vs single mutable counters.
