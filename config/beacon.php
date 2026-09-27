<?php

return [
    'ingest_url' => env('BEACON_INGEST_URL', 'http://localhost:8443'),
    'api_key' => env('BEACON_API_KEY', ''),
    'service_name' => env('BEACON_SERVICE_NAME', env('APP_NAME', 'laravel-service')),
    'environment' => env('BEACON_ENVIRONMENT', env('APP_ENV', 'production')),
    'batch_size' => (int) env('BEACON_BATCH_SIZE', 50),
    'sanitize_pii' => (bool) env('BEACON_SANITIZE_PII', false),
];
