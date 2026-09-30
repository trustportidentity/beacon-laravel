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

### Sampling, noise and privacy

- **Sampling**: `BEACON_SAMPLE_RATE` (0-1) applies to normal requests. Requests that throw are **always** sent.
  Low-traffic apps should use `1`; a low rate means most requests never appear in the dashboard.
- **Noisy endpoints** (cron pings, webhooks): exclude with `BEACON_IGNORE_PATHS`, comma separated, `*` wildcard,
  e.g. `up,health,api/health,api/send_*_mails`.
- **Always redacted, no setting needed**: `Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, CSRF/XSRF headers,
  and the value of any query parameter whose name contains `token`, `secret`, `password`, `auth`, `signature`,
  `session` or is a key (`key`, `cron_key`, `api_key`). Other parameters (`page`, `keyword`) are kept.
- `BEACON_SANITIZE_PII=true` additionally scrubs card-number-like values from URLs and messages.

### Troubleshooting

| Symptom | Check |
|---|---|
| Only a few requests show up | Sample rate below 1, or the path is in `BEACON_IGNORE_PATHS` |
| Nothing shows up | `BEACON_API_KEY` unset, config cached (`php artisan config:clear`), or the server cannot reach `BEACON_INGEST_URL` |
| Requests have no user | The app authenticates in a way Laravel's guards do not see; call `Beacon::identify(...)` yourself |
| Works locally, not in a queue worker | Run `php artisan queue:restart` after changing `.env` |

Telemetry is silent until `BEACON_API_KEY` is set, so the package can ship to every environment safely.

Run the package tests with `composer install && ./vendor/bin/phpunit`.
