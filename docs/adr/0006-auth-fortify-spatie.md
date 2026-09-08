# ADR 0006: Auth with Fortify + Spatie Permission

## Status
Accepted

## Context
The SaaS needs login, registration, 2FA/password flows, and workspace-scoped roles (owner, admin, ops, finance, viewer).

## Decision
Use **Laravel Fortify** for authentication features and **spatie/laravel-permission** for roles/permissions, always scoped to the active workspace where applicable. Authorization checks use Spatie abilities; Fortify handles credentials and session/token issuance.

## Consequences
- Standard Laravel auth UX with less custom code.
- Role/permission seeders and policies become required product surface.
- Avoid duplicating auth logic outside Fortify/Spatie.
