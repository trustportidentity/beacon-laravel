<?php

namespace TrustPortIdentity\Beacon;

use Illuminate\Support\Facades\Facade;

/**
 * @method static Span span(string $name, string $type = 'custom', ?array $metadata = null)
 * @method static void identify(array $user)
 */
class Beacon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeaconManager::class;
    }
}
