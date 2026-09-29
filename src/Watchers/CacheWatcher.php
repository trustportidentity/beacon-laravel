<?php

namespace TrustPortIdentity\Beacon\Watchers;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\KeyForgotten;
use TrustPortIdentity\Beacon\BeaconManager;

class CacheWatcher
{
    public function __construct(
        private readonly BeaconManager $manager
    ) {
    }

    public function recordCacheHit(CacheHit $event): void
    {
        $this->recordSpan('HIT', $event->key, true, $event->tags ?? []);
    }

    public function recordCacheMiss(CacheMissed $event): void
    {
        $this->recordSpan('MISS', $event->key, false, $event->tags ?? []);
    }

    public function recordKeyWritten(KeyWritten $event): void
    {
        $this->recordSpan('SET', $event->key, null, $event->tags ?? []);
    }

    public function recordKeyForgotten(KeyForgotten $event): void
    {
        $this->recordSpan('FORGET', $event->key, null, $event->tags ?? []);
    }

    /** @param array<string, mixed> $tags */
    private function recordSpan(string $op, string $key, ?bool $hit, array $tags): void
    {
        $trace = $this->manager->getCurrentTrace();
        if ($trace === null) {
            return;
        }

        $now = microtime(true);
        $startMs = max(0.0, ($now - $trace->startedAt) * 1000);

        $metadata = [
            'op' => $op,
            'key' => $key,
        ];
        if ($hit !== null) {
            $metadata['hit'] = $hit;
        }

        $spanTags = [
            'operation' => $op,
            'cache_key' => $key,
        ];
        if ($hit !== null) {
            $spanTags['cache_hit'] = $hit ? 'true' : 'false';
        }

        $trace->addSpan([
            'type' => 'cache',
            'name' => sprintf('CACHE %s %s', $op, $key),
            'start_ms' => round($startMs, 2),
            'duration_ms' => 0.5, // Cache events in Laravel don't provide runtime duration
            'metadata' => $metadata,
            'tags' => $spanTags,
        ]);
    }
}
