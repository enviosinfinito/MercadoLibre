# ADR 0012: Object storage — never base64 in DB

## Status
Accepted

## Context
Product images, labels, invoices, and export files bloat the database and slow backups if stored as base64/blobs in rows.

## Decision
Store all binary artifacts in **object storage** (S3-compatible via Flysystem). The database keeps only metadata: path/key, mime, size, checksum, workspace_id. Never persist base64 payloads in MySQL columns for files.

## Consequences
- Scalable media and exports.
- Requires signed URLs, retention, and backup of the bucket.
- Local/minio drivers for development parity.
