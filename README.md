# trustportidentity/beacon-laravel

Official TrustPort Beacon APM SDK for Laravel.

## Install

```bash
composer require trustportidentity/beacon-laravel
```

Package discovery auto-registers `BeaconServiceProvider` and the `Beacon` facade.

## Configure

```env
BEACON_INGEST_URL=https://beacon-api.trustportidentity.com
BEACON_API_KEY=tb_live_your_tenant_key
BEACON_SERVICE_NAME=billing-api
BEACON_ENVIRONMENT=production
```

## Register the middleware

```php
// app/Http/Kernel.php
use TrustPortIdentity\Beacon\BeaconMiddleware;

protected $middleware = [
    BeaconMiddleware::class,
];
```

## Custom spans

```php
use TrustPortIdentity\Beacon\Beacon;

$span = Beacon::span('db.mysql.select_invoices', 'database');
$invoices = Invoice::where('status', 'pending')->get();
$span->setTag('rows', $invoices->count())->end();
```

## Controlling ingest volume

Every trace is already batched (`BEACON_BATCH_SIZE`) instead of one network call per
request. In high-traffic services, also set `BEACON_SAMPLE_RATE` (0.0–1.0, default 1.0) to
trace only a fraction of requests — this is what actually keeps you inside your plan's
monthly quota. Exceptions are always sent regardless of sampling.

```env
BEACON_SAMPLE_RATE=0.2
```

See the full guide at [beacon.trustportidentity.com/help/laravel](https://beacon.trustportidentity.com/help/laravel).

## Migrating from Laravel Nightwatch

Beacon registers itself the way Nightwatch does: install the package, set the env vars, done.

1. Until the package is on Packagist, add the repository to `composer.json`:
   ```json
   "repositories": [{ "type": "vcs", "url": "https://github.com/trustportidentity/beacon-laravel" }]
   ```
2. `composer remove laravel/nightwatch` then `composer require trustportidentity/beacon-laravel:^1.1`
3. In `.env`, replace the `NIGHTWATCH_*` variables with:
   ```
   BEACON_API_KEY=tb_live_...           # your workspace key from Beacon
   BEACON_INGEST_URL=https://beacon-api.trustportidentity.com
   BEACON_SERVICE_NAME=my-app
   BEACON_ENVIRONMENT=production        # label in Beacon (production/staging/local)
   BEACON_SAMPLE_RATE=1                 # 0-1; replaces NIGHTWATCH_REQUEST_SAMPLE_RATE
   ```
4. Stop the Nightwatch agent (`php artisan nightwatch:agent`): Beacon has no agent process.
5. `php artisan config:clear` and `php artisan queue:restart`.

What you get with no code changes: every HTTP request (route, status, redacted headers, the signed-in user from any
guard including Sanctum), database queries with slow-query / N+1 / duplicate detection, queue jobs, cache hits and
misses, and log lines as breadcrumbs, plus unhandled exceptions. Health checks (`up`, `health`, `api/health`) are not
traced; change with `BEACON_IGNORE_PATHS`. Set `BEACON_AUTO_MIDDLEWARE=false` if you register
`TrustPortIdentity\Beacon\BeaconMiddleware` yourself.

Telemetry is silent until `BEACON_API_KEY` is set, so the package can ship to every environment safely.

Run the package tests with `composer install && ./vendor/bin/phpunit`.
