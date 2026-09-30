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
                sampleRate: $config['sample_rate'] ?? 1.0,
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

        $this->registerWatchers();
        $this->registerMiddleware();

        // Flush any queued events when the worker/request finishes.
        $this->app->terminating(function () {
            $this->app->make(BeaconClient::class)->flush();
        });
    }

    /**
     * Adds the request-tracing middleware to the global HTTP stack automatically (like Nightwatch),
     * so installing the package and setting BEACON_API_KEY is all an app needs. Does nothing
     * without an API key, in the console (queue workers have their own watcher), or when the app
     * already registered BeaconMiddleware itself.
     */
    private function registerMiddleware(): void
    {
        $config = $this->app['config']->get('beacon', []);
        if (!($config['auto_middleware'] ?? true) || empty($config['api_key'])) {
            return;
        }
        if ($this->app->runningInConsole() && !($config['auto_middleware_console'] ?? false)) {
            return;
        }
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        if (!method_exists($kernel, 'pushMiddleware')) {
            return;
        }
        if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(BeaconMiddleware::class)) {
            return;
        }
        $kernel->pushMiddleware(BeaconMiddleware::class);
    }

    private function registerWatchers(): void
    {
        if (!isset($this->app['events'])) {
            return;
        }

        $events = $this->app['events'];
        $config = $this->app['config']->get('beacon.watchers', []);

        // 1. Query Watcher (DB::listen)
        if ($config['queries'] ?? true) {
            $queryWatcher = new Watchers\QueryWatcher(
                $this->app->make(BeaconManager::class),
                $config
            );
            $events->listen(\Illuminate\Database\Events\QueryExecuted::class, [$queryWatcher, 'recordQuery']);
        }

        // 2. Queue & Background Job Watcher
        if ($config['jobs'] ?? true) {
            $jobWatcher = new Watchers\JobWatcher(
                $this->app->make(BeaconClient::class),
                $this->app->make(BeaconManager::class)
            );
            $events->listen(\Illuminate\Queue\Events\JobProcessing::class, [$jobWatcher, 'recordJobProcessing']);
            $events->listen(\Illuminate\Queue\Events\JobProcessed::class, [$jobWatcher, 'recordJobProcessed']);
            $events->listen(\Illuminate\Queue\Events\JobFailed::class, [$jobWatcher, 'recordJobFailed']);
            $events->listen(\Illuminate\Queue\Events\JobExceptionOccurred::class, [$jobWatcher, 'recordJobFailed']);
        }

        // 3. Cache Watcher (Redis / Cache operations)
        if ($config['cache'] ?? true) {
            $cacheWatcher = new Watchers\CacheWatcher(
                $this->app->make(BeaconManager::class)
            );
            $events->listen(\Illuminate\Cache\Events\CacheHit::class, [$cacheWatcher, 'recordCacheHit']);
            $events->listen(\Illuminate\Cache\Events\CacheMissed::class, [$cacheWatcher, 'recordCacheMiss']);
            $events->listen(\Illuminate\Cache\Events\KeyWritten::class, [$cacheWatcher, 'recordKeyWritten']);
            $events->listen(\Illuminate\Cache\Events\KeyForgotten::class, [$cacheWatcher, 'recordKeyForgotten']);
        }

        // 4. Log Breadcrumbs Watcher
        if ($config['logs'] ?? true) {
            $logWatcher = new Watchers\LogWatcher(
                $this->app->make(BeaconManager::class)
            );
            $events->listen(\Illuminate\Log\Events\MessageLogged::class, [$logWatcher, 'recordLog']);
        }
    }
}
