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

See the full guide at [beacon.trustportidentity.com/help/laravel](https://beacon.trustportidentity.com/help/laravel).
