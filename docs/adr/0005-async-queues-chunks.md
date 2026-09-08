# ADR 0005: Async queues and chunked syncs

## Status
Accepted

## Context
Catalog, orders, and inventory syncs can be large and rate-limited. Synchronous HTTP requests will time out and overload marketplace APIs.

## Decision
All marketplace I/O and heavy reports run on **queues** (Horizon). Syncs process **chunks/pages** with cursor/offset checkpoints per connection. Jobs are idempotent, tenant-scoped, and retry with classified backoff. Webhooks enqueue work; they never do full sync inline.

## Consequences
- Resilient, resumable syncs.
- Need observability (failed jobs, lag, rate limits).
- UX must show sync status instead of waiting for completion.
