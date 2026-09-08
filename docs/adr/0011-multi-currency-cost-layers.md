# ADR 0011: Multi-currency and cost layers

## Status
Accepted

## Context
Sellers operate in MXN/USD/etc., pay fees in marketplace currency, and need landed cost (product + shipping + duties + fees) for true margin.

## Decision
Persist every monetary fact with its **original currency**. Convert for reporting using explicit FX rates/snapshots (never silent implicit conversion). Maintain **cost layers** on the canonical SKU (unit cost, inbound, fees allocations, adjustments). Margin reports compose revenue and cost layers with documented FX policy.

## Consequences
- Accurate multi-currency P&L.
- Need FX rate source and audit of conversion timestamps.
- UI must show currency clearly; aggregates declare reporting currency.
