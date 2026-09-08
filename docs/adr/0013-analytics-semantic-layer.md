# ADR 0013: Analytics semantic layer + AST query engine

## Status
Accepted

## Context
We need Zoho-like dashboards and self-service reports on a multi-tenant shared DB. Users must build pivots, charts, and filters without writing SQL. Platform admins publish template dashboards that every workspace consumes with its own data.

## Decision
1. **Semantic catalog** in code (`app/Domain/Analytics/Catalog`) defines datasets, columns, allowed aggregations, and whitelisted joins. No raw SQL from clients.
2. **Query AST** (JSON) describes dataset, dimensions, measures, filters, sort, limit, optional pivot/formulas. A `QueryEngine` compiles the AST to Query Builder, always scoping `workspace_id` from `TenantContext`.
3. **Dashboards**: `visibility = platform_template` rows have `workspace_id = null` and are authored only by platform admins; workspace/personal dashboards are tenant-owned. Templates execute against the current workspace’s data.
4. **Exports** reuse `export_runs` / `scheduled_exports`, storing the AST (or `analytics_report_id`) and writing files via queued jobs (ADR 0007).

## Consequences
- Safe tenancy by construction; catalog evolves with product domains.
- Full SQL/BI freedom is intentionally out of scope.
- Templates are global definitions; cloning materializes an editable workspace copy.
