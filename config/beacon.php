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
];
