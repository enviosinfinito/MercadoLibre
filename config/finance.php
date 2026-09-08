<?php

/**
 * Finance defaults for expected P&L (fees + government withholdings)
 * and cash-out reconciliation (collections → releases → withdrawals).
 *
 * Prefer REAL Mercado Libre amounts when available:
 * - Commission: order_items.sale_fee or collections.marketplace_fee
 * - Shipping: GET /shipments/{id}/costs → senders[].cost
 * - Tax TOTAL: residual = revenue − fee − shipping − collections.net_received_amount
 *
 * ISR/IVA line split is NOT in orders/collections. The Billing API that exposes
 * it returns 403 for this app today. When residual ≈ MX UI formula, we label the
 * residual parts as ISR/IVA for display; otherwise a single "Impuestos" line.
 *
 * Cash capabilities are probed per connection and stored in connection_capabilities:
 * - cash.ml_collections
 * - cash.mp_settlement_report
 * - cash.mp_release_report
 * - cash.ml_billing
 */
return [

    'estimated_sale_fee_rate' => env('FINANCE_ESTIMATED_SALE_FEE_RATE', '0.12'),

    'tax_retention' => [
        'price_includes_iva' => filter_var(env('FINANCE_TAX_PRICE_INCLUDES_IVA', true), FILTER_VALIDATE_BOOL),
        'iva_included_rate' => env('FINANCE_TAX_IVA_INCLUDED_RATE', '0.16'),
        // Used only to label/split a real residual (or as offline fallback).
        'isr_rate' => env('FINANCE_TAX_ISR_RATE', '0.025'),
        'iva_rate' => env('FINANCE_TAX_IVA_RATE', '0.08'),
    ],

    'mercadopago' => [
        'api_base_url' => env('MERCADOPAGO_API_BASE_URL', 'https://api.mercadopago.com'),
        // Settlement POST /config rejects DATE/ORDER_ID/SHIPPING_ID/IS_RELEASED on some sellers.
        // Use TRANSACTION_DATE (+ money fields) — same keys IngestMercadoPagoReportRows already reads.
        'report_columns' => [
            'TRANSACTION_DATE',
            'SOURCE_ID',
            'EXTERNAL_REFERENCE',
            'TRANSACTION_TYPE',
            'TRANSACTION_AMOUNT',
            'FEE_AMOUNT',
            'MKP_FEE_AMOUNT',
            'SHIPPING_FEE_AMOUNT',
            'TAXES_AMOUNT',
            'FINANCING_FEE_AMOUNT',
            'SETTLEMENT_NET_AMOUNT',
            'SETTLEMENT_DATE',
            'MONEY_RELEASE_DATE',
            'DESCRIPTION',
        ],
        // Release report (= “Liberación de dinero” / available money). Needs amount columns
        // for expected-vs-actual; DATE/SOURCE_ID alone is not enough to reconcile.
        'release_report_columns' => [
            'DATE',
            'SOURCE_ID',
            'EXTERNAL_REFERENCE',
            'DESCRIPTION',
            'NET_CREDIT_AMOUNT',
            'NET_DEBIT_AMOUNT',
            'GROSS_AMOUNT',
            'MP_FEE_AMOUNT',
            'TAXES_AMOUNT',
            'BALANCE_AMOUNT',
            'RECORD_TYPE',
            'PAYMENT_METHOD',
        ],
        'settlement_report_path' => '/v1/account/settlement_report',
        'release_report_path' => '/v1/account/release_report',
        // Release reports: short lookback (generation is slow; day windows work better).
        'release_default_days' => (int) env('FINANCE_MP_RELEASE_DEFAULT_DAYS', 2),
        'settlement_default_days' => (int) env('FINANCE_MP_SETTLEMENT_DEFAULT_DAYS', 14),
        // Background poll job backoff (seconds). No usleep in queue workers.
        'poll_max_attempts' => (int) env('FINANCE_MP_REPORT_POLL_MAX_ATTEMPTS', 24),
        'poll_delays_seconds' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('FINANCE_MP_REPORT_POLL_DELAYS', '30,60,120,180,300,300,480')),
        ))),
        // Inline --sync debug only (short).
        'poll_attempts' => (int) env('FINANCE_MP_REPORT_POLL_ATTEMPTS', 12),
        'poll_sleep_ms' => (int) env('FINANCE_MP_REPORT_POLL_SLEEP_MS', 2500),
        // Keep last N downloaded CSVs per connection+kind on disk.
        'report_files_keep' => (int) env('FINANCE_MP_REPORT_FILES_KEEP', 5),
    ],

    'cash' => [
        'diff_tolerance' => env('FINANCE_CASH_DIFF_TOLERANCE', '0.01'),
        // Days after successful delivery before money should already be released to the seller.
        'release_grace_days' => (int) env('FINANCE_CASH_RELEASE_GRACE_DAYS', 2),
        // Max calendar days per "Actualizar datos" click (release reports are slow).
        'release_sync_max_days' => (int) env('FINANCE_CASH_RELEASE_SYNC_MAX_DAYS', 14),
        // Chunk size when requesting MP release/settlement reports.
        'release_sync_chunk_days' => (int) env('FINANCE_CASH_RELEASE_SYNC_CHUNK_DAYS', 2),
        'capability_keys' => [
            'collections' => 'cash.ml_collections',
            'settlement_report' => 'cash.mp_settlement_report',
            'release_report' => 'cash.mp_release_report',
            'billing' => 'cash.ml_billing',
        ],
    ],

];
