# Product Ads — write endpoint map

Last reviewed: 2026-08-09

## Permission

Mercado Libre Developers → Application → **Permisos funcionales** → **Publicidad** (read + write).  
Without this, calls return `403 PA_UNAUTHORIZED_RESULT_FROM_POLICIES`.

Re-authorize seller connections after enabling the permission.

## Headers

- `Authorization: Bearer {access_token}`
- `api-version: 2`
- `Content-Type: application/json`

## Mapped paths (config/ads.php)

Primary (marketplace) + fallback (legacy `/advertising`):

| Action | Method | Paths |
|--------|--------|------|
| Update campaign (status, budget, roas_target) | PUT | `/marketplace/advertising/{site_id}/product_ads/campaigns/{campaign_id}`, `/advertising/product_ads/campaigns/{campaign_id}` |
| Update ad/item status | PUT | `/marketplace/advertising/{site_id}/product_ads/ads/{item_id}`, `/advertising/product_ads/ads/{item_id}` |
| Create campaign | POST | `/marketplace/advertising/{site_id}/advertisers/{advertiser_id}/product_ads/campaigns`, `/advertising/advertisers/{advertiser_id}/product_ads/campaigns` |
| Add items to campaign | POST | `.../campaigns/{campaign_id}/items`, `/advertising/product_ads/campaigns/{campaign_id}/ads` |

Public docs emphasize GET metrics; write shapes above follow the marketplace Advertising API used by partners.  
`ProductAdsWriteClient::probeWriteAccess()` validates at runtime. On 403/404 the assistant stays in **suggest-only** and records proposals for the seller to apply in ML Ads UI.

Assistant UI: `/ads/assistant` · command `ads:evaluate-rules` (scheduled every 4h).

## Example payloads

Pause campaign:

```json
{ "status": "paused" }
```

Set ROAS target + budget:

```json
{ "roas_target": 5, "daily_budget": 500 }
```

Pause ad:

```json
{ "status": "paused" }
```

Create campaign (custom mode):

```json
{
  "name": "Rentables - protect",
  "status": "active",
  "strategy": "PROFITABILITY",
  "roas_target": 5,
  "daily_budget": 300,
  "channel": "marketplace"
}
```
