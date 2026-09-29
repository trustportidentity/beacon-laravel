<?php

namespace TrustPortIdentity\Beacon\Watchers;

use Illuminate\Log\Events\MessageLogged;
use TrustPortIdentity\Beacon\BeaconManager;

class LogWatcher
{
    public function __construct(
        private readonly BeaconManager $manager
    ) {
    }

    public function recordLog(object $event): void
    {
        $trace = $this->manager->getCurrentTrace();
        if ($trace === null) {
            return;
        }

        $trace->addBreadcrumb([
            'category' => 'log',
            'level' => $event->level,
            'message' => (string) $event->message,
            'context' => !empty($event->context) ? $this->sanitizeContext($event->context) : null,
        ]);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'card'];

        foreach ($context as $k => $v) {
            $lower = strtolower((string) $k);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sens) {
                if (str_contains($lower, $sens)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$k] = '[redacted]';
            } elseif (is_scalar($v) || is_null($v)) {
                $sanitized[$k] = $v;
            } elseif (is_array($v)) {
                $sanitized[$k] = $this->sanitizeContext($v);
            } else {
                $sanitized[$k] = (string) $v;
            }
        }

        return $sanitized;
    }
}
