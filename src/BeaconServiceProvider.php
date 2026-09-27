<?php

namespace TrustPortIdentity\Beacon;

use Illuminate\Support\ServiceProvider;

class BeaconServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/beacon.php', 'beacon');

        $this->app->singleton(BeaconClient::class, function ($app) {
            $config = $app['config']['beacon'];
            return new BeaconClient(
                apiKey: $config['api_key'] ?? '',
                serviceName: $config['service_name'] ?? config('app.name', 'laravel-service'),
                ingestUrl: $config['ingest_url'] ?? 'http://localhost:8443',
                environment: $config['environment'] ?? config('app.env', 'production'),
                batchSize: $config['batch_size'] ?? 50,
                sanitizePii: $config['sanitize_pii'] ?? false,
            );
        });

        $this->app->singleton(BeaconManager::class, function ($app) {
            return new BeaconManager($app->make(BeaconClient::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/beacon.php' => config_path('beacon.php'),
        ], 'beacon-config');

        // Flush any queued events when the worker/request finishes.
        $this->app->terminating(function () {
            $this->app->make(BeaconClient::class)->flush();
        });
    }
}
