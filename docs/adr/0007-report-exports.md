# ADR 0007: Report exports as async jobs

## Status
Accepted

## Context
P&L, inventory, and sales exports can be large and must not block the request lifecycle or embed files in the DB.

## Decision
Exports are **queued jobs** that write files to object storage and notify the user with a signed/download URL (or in-app download record). Prefer Maatwebsite Excel / streaming writers. Export rows remain tenant-scoped; no synchronous multi-megabyte downloads from controllers.

## Consequences
- Predictable request latency.
- Storage + retention policy required.
- UI shows export status (queued/ready/failed).
