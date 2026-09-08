# ADR 0009: UI via shadcn-style design system

## Status
Accepted

## Context
Inertia + React needs a consistent component library that is copy-owned (not a black-box dependency) and themeable for the SaaS shell.

## Decision
Build the frontend on a **shadcn/ui-style** design system (Radix + Tailwind primitives checked into the repo). App chrome, forms, tables, and dialogs reuse these primitives; avoid ad-hoc one-off component styles for core surfaces.

## Consequences
- Consistent UX and accessible primitives.
- Team owns upgrades and theming.
- Design tokens/CSS variables must be documented and reused.
