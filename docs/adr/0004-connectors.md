# ADR 0004: Marketplace connectors via ConnectorInterface

## Status
Accepted

## Context
Mercado Libre, Amazon, and future channels differ in auth, webhooks, and APIs but share the same domain operations (pull, push, reconcile).

## Decision
All marketplace integrations implement `App\Integrations\Contracts\ConnectorInterface` with a fixed lifecycle: authorize, tokens, capabilities, bootstrap, pull/fetch, webhook parse, push, reconcile, and error classification. A `ConnectorRegistry` resolves connectors by channel; `CapabilityMatrix` declares what each channel supports. Domain code depends on the contract, not vendor SDKs.

## Consequences
- Uniform jobs and UI gated by capabilities.
- New channels = new connector + matrix entry.
- Vendor-specific quirks stay inside each connector package.
