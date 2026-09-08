<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Diagnostic HTTP logging window
    |--------------------------------------------------------------------------
    |
    | When a platform admin enables sync_http_logs recording, it stays on
    | for this many minutes and then auto-disables.
    |
    */

    'diagnostic_http_logging_minutes' => (int) env('DIAGNOSTIC_HTTP_LOGGING_MINUTES', 10),

];
