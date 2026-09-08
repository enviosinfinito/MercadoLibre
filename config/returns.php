<?php

/**
 * Returns analytics module — thresholds, risk score weights, hybrid message analysis.
 */
return [

    /**
     * Universe of post_sale_outcome values included in the Returns module.
     *
     * @var list<string>
     */
    'outcomes' => ['returned', 'refunded', 'partial_refunded'],

    'rate_thresholds' => [
        'normal_max' => 0.03,
        'attention_max' => 0.05,
        'high_max' => 0.10,
    ],

    'risk_score' => [
        'weights' => [
            'return_rate' => 25,
            'volume' => 10,
            'loss_share' => 15,
            'vs_baseline' => 20,
            'recent_growth' => 10,
            'reason_concentration' => 10,
            'defective_boost' => 5,
            'variant_concentration' => 5,
        ],
        'levels' => [
            'normal_max' => 39,
            'attention_max' => 59,
            'high_max' => 79,
        ],
        'min_sales_for_critical' => 30,
        'confidence_multipliers' => [
            'low' => 0.25,
            'medium' => 0.5,
            'high' => 0.75,
            'very_high' => 1.0,
        ],
        'confidence_sales_buckets' => [
            'low' => 5,
            'medium' => 30,
            'high' => 100,
        ],
    ],

    'anomaly' => [
        'min_sales' => 10,
        'min_returns' => 3,
        'baseline_multiplier' => 2.0,
        'category_multiplier' => 1.5,
        'spike_multiplier' => 2.0,
        'reason_concentration_min' => 0.60,
        'variant_outlier_multiplier' => 3.0,
    ],

    'sample' => [
        'min_sales_for_patterns' => 10,
        'min_sales_for_comparisons' => 5,
    ],

    'ai' => [
        'enabled' => (bool) env('RETURNS_AI_ENABLED', false),
        'provider' => env('RETURNS_AI_PROVIDER', 'openai'),
        'model' => env('RETURNS_AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('RETURNS_AI_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('RETURNS_AI_BASE_URL', 'https://api.openai.com/v1'),
        'confidence_threshold' => 'medium',
        'max_corpus_chars' => 1500,
        'max_completion_tokens' => 120,
        'timeout_seconds' => 15,
    ],

    /**
     * Keyword dictionary (normalized, accent-insensitive) → reason_group.
     *
     * @var array<string, list<string>>
     */
    'reason_keywords' => [
        'size' => [
            'talla', 'talle', 'queda grande', 'queda chico', 'queda chica',
            'mas chico', 'mas grande', 'muy chico', 'muy grande', 'no me queda',
            'medida', 'medidas', 'size',
        ],
        'defective' => [
            'defectuoso', 'defectuosa', 'no prende', 'no funciona', 'roto', 'rota',
            'se rompio', 'danado', 'danada', 'falla', 'fallando', 'no enciende',
            'malo', 'mala calidad', 'calidad',
        ],
        'not_as_expected' => [
            'no era lo esperado', 'no es lo esperado', 'no coincide', 'diferente a la foto',
            'no es como la foto', 'descripcion', 'esperaba', 'no me gusto', 'color no',
        ],
        'wrong_item' => [
            'producto equivocado', 'articulo equivocado', 'me enviaron otro', 'otro producto',
            'no es el que pedi', 'incorrecto',
        ],
        'logistics' => [
            'paquete danado', 'caja danada', 'llegó danado', 'llego danado',
            'golpeado', 'abierto', 'mal embalado', 'transporte',
        ],
        'late_delivery' => [
            'llego tarde', 'demora', 'retraso', 'nunca llego', 'no llego',
        ],
        'changed_mind' => [
            'arrepentimiento', 'ya no lo quiero', 'cambie de opinion', 'me arrepenti',
        ],
    ],

    'reason_groups' => [
        'defective' => 'Producto defectuoso',
        'size' => 'Talla incorrecta',
        'not_as_expected' => 'No era lo esperado',
        'wrong_item' => 'Producto diferente',
        'logistics' => 'Problema logístico',
        'late_delivery' => 'Entrega tardía',
        'changed_mind' => 'Arrepentimiento',
        'other' => 'Otros',
    ],
];
