<?php

namespace TrustPortIdentity\Beacon\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use TrustPortIdentity\Beacon\BeaconManager;

class QueryWatcher
{
    public function __construct(
        private readonly BeaconManager $manager,
        private readonly array $config = []
    ) {
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $trace = $this->manager->getCurrentTrace();
        if ($trace === null) {
            return;
        }

        $now = microtime(true);
        $durationMs = (float) $event->time; // QueryExecuted->time is already in milliseconds
        $startMs = max(0.0, ($now - $trace->startedAt) * 1000 - $durationMs);

        $tags = [];
        $metadata = [
            'driver' => $event->connectionName,
            'connection' => $event->connectionName,
            'rows_returned' => null,
            'bindings_count' => count($event->bindings),
        ];

        // 1. Slow Query Detection
        $thresholdMs = (float) ($this->config['slow_query_threshold_ms'] ?? 100.0);
        if ($durationMs >= $thresholdMs) {
            $tags['slow_query'] = 'true';
            $tags['threshold_ms'] = (string) $thresholdMs;
        }

        // 2. Duplicate Query Detection
        if ($this->config['detect_duplicates'] ?? true) {
            $duplicateKey = md5($event->sql . serialize($event->bindings));
            $count = ($trace->queryFingerprints[$duplicateKey] ?? 0) + 1;
            $trace->queryFingerprints[$duplicateKey] = $count;

            if ($count > 1) {
                $tags['duplicate_query'] = 'true';
                $tags['duplicate_count'] = (string) $count;
            }
        }

        // 3. N+1 Query Detection (Same parameterized SQL template executed repeatedly)
        if ($this->config['detect_n_plus_one'] ?? true) {
            $templateKey = md5($event->sql);
            $templateCount = ($trace->queryTemplates[$templateKey] ?? 0) + 1;
            $trace->queryTemplates[$templateKey] = $templateCount;

            if ($templateCount >= 3) {
                $tags['n_plus_one_suspect'] = 'true';
                $tags['template_occurrences'] = (string) $templateCount;
            }
        }

        // 4. Find Application Caller Frame outside vendor/
        $caller = $this->findCaller();
        if ($caller !== null) {
            $tags['file'] = $caller['file'];
            $tags['line'] = (string) $caller['line'];
        }

        $trace->addSpan([
            'type' => 'database',
            'name' => $event->sql,
            'start_ms' => round($startMs, 2),
            'duration_ms' => round($durationMs, 2),
            'metadata' => $metadata,
            'tags' => !empty($tags) ? $tags : null,
        ]);
    }

    /**
     * Finds the first stack frame outside framework/vendor internals to pinpoint the
     * exact controller or model line that executed this database query.
     *
     * @return array{file: string, line: int}|null
     */
    private function findCaller(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }

            $file = $frame['file'];
            // Skip vendor, framework, and beacon files
            if (
                !str_contains($file, '/vendor/') &&
                !str_contains($file, 'TrustPortIdentity/Beacon')
            ) {
                return [
                    'file' => $file,
                    'line' => (int) $frame['line'],
                ];
            }
        }

        return null;
    }
}
