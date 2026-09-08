# ADR 0001: Tenancy via shared DB + workspace_id

## Status
Accepted

## Context
We need multi-tenant isolation for a SaaS without the operational cost of a database-per-tenant at MVP scale.

## Decision
Use a **shared database** with row-level tenancy keyed by `workspace_id` on all tenant-owned tables. Every query, job, and export must resolve and scope by the current workspace via `TenantContext`. Cross-workspace access is forbidden except for platform/admin tooling.

## Consequences
- Simpler ops and migrations; one schema for all tenants.
- Requires disciplined scoping (global scopes, middleware, job payloads).
- Future split to DB-per-tenant remains possible if we keep `workspace_id` as the tenant boundary.
