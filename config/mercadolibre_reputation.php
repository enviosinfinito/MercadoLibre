<?php

/**
 * Seller reputation quality thresholds by site (official Mercado Libre docs).
 * Rates are fractions (0.01 = 1%). A metric falls into the first band whose max is >= rate.
 *
 * @see https://developers.mercadolibre.com.bo/es_ar/reputacion-de-vendedores
 */
return [
    'timeline_days' => (int) env('MELI_REPUTATION_TIMELINE_DAYS', 180),

    /**
     * Per-site max rate inclusive per band. Order matters: leader → green → yellow → orange; above orange max = red.
     *
     * @var array<string, array<string, array{leader: float, green: float, yellow: float, orange: float}>>
     */
    'thresholds' => [
        'MLB' => [
            'claims' => ['leader' => 0.01, 'green' => 0.02, 'yellow' => 0.045, 'orange' => 0.08],
            'cancellations' => ['leader' => 0.005, 'green' => 0.015, 'yellow' => 0.035, 'orange' => 0.04],
            'delayed_handling_time' => ['leader' => 0.06, 'green' => 0.10, 'yellow' => 0.18, 'orange' => 0.22],
        ],
        'MLA' => [
            'claims' => ['leader' => 0.01, 'green' => 0.015, 'yellow' => 0.03, 'orange' => 0.06],
            'cancellations' => ['leader' => 0.005, 'green' => 0.01, 'yellow' => 0.025, 'orange' => 0.03],
            'delayed_handling_time' => ['leader' => 0.08, 'green' => 0.10, 'yellow' => 0.15, 'orange' => 0.22],
        ],
        'MLM' => [
            'claims' => ['leader' => 0.01, 'green' => 0.015, 'yellow' => 0.03, 'orange' => 0.06],
            'cancellations' => ['leader' => 0.005, 'green' => 0.01, 'yellow' => 0.025, 'orange' => 0.03],
            'delayed_handling_time' => ['leader' => 0.08, 'green' => 0.10, 'yellow' => 0.15, 'orange' => 0.22],
        ],
        'MCO' => [
            'claims' => ['leader' => 0.025, 'green' => 0.035, 'yellow' => 0.055, 'orange' => 0.07],
            'cancellations' => ['leader' => 0.015, 'green' => 0.025, 'yellow' => 0.07, 'orange' => 0.09],
            'delayed_handling_time' => ['leader' => 0.10, 'green' => 0.12, 'yellow' => 0.18, 'orange' => 0.26],
        ],
        'MLU' => [
            'claims' => ['leader' => 0.025, 'green' => 0.035, 'yellow' => 0.055, 'orange' => 0.07],
            'cancellations' => ['leader' => 0.015, 'green' => 0.025, 'yellow' => 0.07, 'orange' => 0.09],
            'delayed_handling_time' => ['leader' => 0.10, 'green' => 0.12, 'yellow' => 0.18, 'orange' => 0.26],
        ],
        'MLC' => [
            'claims' => ['leader' => 0.025, 'green' => 0.035, 'yellow' => 0.055, 'orange' => 0.07],
            'cancellations' => ['leader' => 0.015, 'green' => 0.025, 'yellow' => 0.07, 'orange' => 0.09],
            'delayed_handling_time' => ['leader' => 0.10, 'green' => 0.12, 'yellow' => 0.18, 'orange' => 0.26],
        ],
        'MEC' => [
            'claims' => ['leader' => 0.04, 'green' => 0.04, 'yellow' => 0.06, 'orange' => 0.08],
            'cancellations' => ['leader' => 0.03, 'green' => 0.03, 'yellow' => 0.08, 'orange' => 0.11],
            'delayed_handling_time' => ['leader' => 0.12, 'green' => 0.12, 'yellow' => 0.20, 'orange' => 0.30],
        ],
        'MPE' => [
            'claims' => ['leader' => 0.02, 'green' => 0.02, 'yellow' => 0.045, 'orange' => 0.08],
            'cancellations' => ['leader' => 0.025, 'green' => 0.025, 'yellow' => 0.07, 'orange' => 0.09],
            'delayed_handling_time' => ['leader' => 0.12, 'green' => 0.12, 'yellow' => 0.18, 'orange' => 0.26],
        ],
    ],

    'fallback_site' => 'MLM',

    'purchase_experience_locales' => [
        'MLM' => 'es_MX',
        'MLA' => 'es_AR',
        'MLB' => 'pt_BR',
        'MLC' => 'es_CL',
        'MCO' => 'es_CO',
        'MLU' => 'es_UY',
        'MPE' => 'es_PE',
        'MEC' => 'es_EC',
    ],

    'purchase_experience_fallback_locale' => 'es_MX',
];
