# ADR 0002: Money as DECIMAL / string — never float

## Status
Accepted

## Context
Marketplace fees, costs, FX, and P&L require exact arithmetic. IEEE floats introduce rounding errors that break reconciliations and ledgers.

## Decision
Store monetary amounts as `DECIMAL` in the database and represent them in PHP with the `Money` value object using a **string/decimal amount** plus `currency_code`. Never use `float`/`double` for money. Arithmetic (`add`/`subtract`) returns new immutable `Money` instances and uses BCMath or equivalent string decimal math.

## Consequences
- Safe aggregation and reconciliation.
- Slightly more verbose code than float math.
- All APIs and exports must serialize money as strings (or scaled integers), not floats.
