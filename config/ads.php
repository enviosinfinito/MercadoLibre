<?php

/**
 * Product Ads assistant + autopilot defaults.
 *
 * Write paths are the conventional marketplace Advertising API shapes used by
 * partners. They are probed at runtime; if ML returns 403/404 the assistant
 * stays in suggest-only mode until Publicidad write is enabled on the app.
 */
return [

    'autopilot_mode_default' => env('ADS_AUTOPILOT_MODE', 'shadow'), // shadow|approve|auto

    'ads_budget_share_of_margin' => env('ADS_BUDGET_SHARE_OF_MARGIN', '0.35'),

    'learning_days' => (int) env('ADS_LEARNING_DAYS', 14),

    'guardrails' => [
        'max_pauses_per_cycle' => (int) env('ADS_MAX_PAUSES_PER_CYCLE', 25),
        'max_spend_share_paused_per_cycle' => (float) env('ADS_MAX_SPEND_SHARE_PAUSED', 0.35),
        'entity_cooldown_hours' => (int) env('ADS_ENTITY_COOLDOWN_HOURS', 48),
        'min_spend_for_waste_pause' => env('ADS_MIN_SPEND_WASTE_PAUSE', '50'),
        'min_clicks_for_roas_action' => (int) env('ADS_MIN_CLICKS_ROAS_ACTION', 15),
        'write_failure_circuit_breaker' => (int) env('ADS_WRITE_FAILURE_BREAKER', 5),
    ],

    'evaluate_lookback_days' => (int) env('ADS_EVALUATE_LOOKBACK_DAYS', 14),

    /**
     * Longer window for pattern diagnostics (weekday clusters, worst-ROAS factors).
     * Immediate scorecard / autopilot keep evaluate_lookback_days.
     */
    'pattern_lookback_days' => (int) env('ADS_PATTERN_LOOKBACK_DAYS', 90),

    /** Default Product Ads metrics sync window (ML allows up to ~90d). */
    'sync_lookback_days' => (int) env('ADS_SYNC_LOOKBACK_DAYS', 90),

    /** Cap of per-item daily metric GETs during sync (ads with spend/clicks only). */
    'sync_max_item_daily_fetches' => (int) env('ADS_SYNC_MAX_ITEM_DAILY_FETCHES', 150),

    /**
     * Endpoint map (api-version: 2). Placeholders: {site_id}, {advertiser_id}, {campaign_id}, {item_id}
     * Primary = marketplace Advertising API; fallbacks = legacy /advertising paths used by sync.
     */
    'write_endpoints' => [
        'update_campaign' => [
            '/marketplace/advertising/{site_id}/product_ads/campaigns/{campaign_id}',
            '/advertising/product_ads/campaigns/{campaign_id}',
        ],
        'update_ad' => [
            '/marketplace/advertising/{site_id}/product_ads/ads/{item_id}',
            '/advertising/product_ads/ads/{item_id}',
        ],
        'create_campaign' => [
            '/marketplace/advertising/{site_id}/advertisers/{advertiser_id}/product_ads/campaigns',
            '/advertising/advertisers/{advertiser_id}/product_ads/campaigns',
        ],
        'add_campaign_items' => [
            '/marketplace/advertising/{site_id}/advertisers/{advertiser_id}/product_ads/campaigns/{campaign_id}/items',
            '/advertising/product_ads/campaigns/{campaign_id}/ads',
        ],
    ],

    'presets' => [
        'protect_profit' => [
            'label' => 'Proteger margen',
            'description' => 'Pausa desperdicio y anuncios lejos del ROAS objetivo. Ideal para empezar.',
        ],
        'balanced' => [
            'label' => 'Equilibrado',
            'description' => 'Corta pérdidas y sugiere potenciar lo que ya es rentable.',
        ],
        'scale' => [
            'label' => 'Crecer',
            'description' => 'Más agresivo en presupuesto; solo pausa desperdicio extremo.',
        ],
    ],

];
