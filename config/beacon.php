<?php

return [
    'ingest_url' => env('BEACON_INGEST_URL', 'http://localhost:8443'),
    'api_key' => env('BEACON_API_KEY', ''),
    'service_name' => env('BEACON_SERVICE_NAME', env('APP_NAME', 'laravel-service')),
    'environment' => env('BEACON_ENVIRONMENT', env('APP_ENV', 'production')),
    'batch_size' => (int) env('BEACON_BATCH_SIZE', 50),
    'sanitize_pii' => (bool) env('BEACON_SANITIZE_PII', false),

    // Fraction of requests actually traced and sent to Beacon, from 0.0 (none) to 1.0
    // (all, the default). Lower it in high-traffic services to control ingest volume and
    // stay within your plan's monthly quota - e.g. 0.1 traces ~10% of requests. Exceptions
    // are always sent regardless of this setting.
    'sample_rate' => (float) env('BEACON_SAMPLE_RATE', 1.0),

    // Nightwatch-grade automatic event watchers
    'watchers' => [
        'queries' => (bool) env('BEACON_WATCH_QUERIES', true),
        'slow_query_threshold_ms' => (float) env('BEACON_SLOW_QUERY_MS', 100.0),
        'detect_n_plus_one' => (bool) env('BEACON_DETECT_N_PLUS_ONE', true),
        'detect_duplicates' => (bool) env('BEACON_DETECT_DUPLICATES', true),
        'jobs' => (bool) env('BEACON_WATCH_JOBS', true),
        'cache' => (bool) env('BEACON_WATCH_CACHE', true),
        'logs' => (bool) env('BEACON_WATCH_LOGS', true),
    ],
];
