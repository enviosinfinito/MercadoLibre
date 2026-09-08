<?php

return [
    'batch_size' => (int) env('EXPORT_BATCH_SIZE', 200),

    'max_ids_per_request' => (int) env('EXPORT_MAX_IDS_PER_REQUEST', 5000),

    'stalled_after_seconds' => (int) env('EXPORT_STALLED_AFTER_SECONDS', 600),

    'preview_page_limit' => (int) env('EXPORT_PREVIEW_PAGE_LIMIT', 200),

    'preview_page_max' => (int) env('EXPORT_PREVIEW_PAGE_MAX', 500),

    'preview_max_all_rows' => (int) env('EXPORT_PREVIEW_MAX_ALL_ROWS', 5000),

    'file_retention_days' => (int) env('EXPORT_FILE_RETENTION_DAYS', 14),

    'history_retention_days' => (int) env('EXPORT_HISTORY_RETENTION_DAYS', 90),

    'history_page_size' => (int) env('EXPORT_HISTORY_PAGE_SIZE', 20),

    'queue' => env('EXPORT_QUEUE', 'exports'),

    'disk' => env('EXPORT_DISK', env('FILESYSTEM_DISK', 'local')),

    'formula_expression_max_length' => 500,

    'enabled_delivery_channels' => ['email'],
];
