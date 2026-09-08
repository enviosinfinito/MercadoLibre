<?php

/**
 * Tokens de layout (SlideOver widths, etc.).
 * Se inyectan como CSS variables en app.blade.php vía layout-content-tokens.
 */
return [
    /**
     * Anchos de Slider Windows (SlideOverPanel).
     * Porcentajes enteros del viewport. Desktop: base; anidados restan step hasta min.
     * Móvil (< md): siempre 100% (no usan estos valores).
     */
    'slide_over' => [
        'width_percent' => (int) env('SLIDE_OVER_WIDTH_PERCENT', 45),
        'nested_step_percent' => (int) env('SLIDE_OVER_NESTED_STEP_PERCENT', 5),
        'min_width_percent' => (int) env('SLIDE_OVER_MIN_WIDTH_PERCENT', 30),
    ],
];
