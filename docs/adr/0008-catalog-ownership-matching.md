# ADR 0008: Catalog ownership and listing matching

## Status
Accepted (amended)

## Context
Marketplaces expose listings that may map to one internal SKU, variants, or unmatched noise. Ownership and matching must be explicit for inventory and costs. Operators expect items already live on Mercado Libre to exist in the workspace catalog so cost and stock can be attached.

## Decision
The workspace owns a **canonical catalog** (products/SKUs). Marketplace listings are linked via **matches**.

On listing sync (`UpsertChannelListing`):
1. Prefer an existing canonical `Variant` when `sku_external` matches a workspace SKU.
2. If a listing variant remains unmatched and the listing is `active` or `paused`, **adopt** it: create (or reuse) a Product for the listing and Variants for unmatched channel variants, using `sku_external` or a stable synthetic SKU (`ML-{itemId}` / `ML-{itemId}-V-{variationId}`).
3. Manual rematch remains available to reassign a listing to a different canonical SKU.

Inventory and cost layers apply to the canonical SKU; channel listings inherit through the match. Adoption does **not** invent cost layers — cost is still entered via inventory receipts.

## Consequences
- Sync no longer leaves active/paused listings permanently unmatched.
- Synthetic SKUs are deterministic so re-sync is idempotent.
- Matching UX remains useful for correcting wrong auto-adoptions.
- Cost/finance alerts should key off marketplace listings whose matched variants lack cost layers.
