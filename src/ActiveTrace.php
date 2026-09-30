<?php

namespace TrustPortIdentity\Beacon;

class ActiveTrace
{
    public string $traceId;
    public ?string $parentSpanId = null;
    public string $spanId;
    public float $startedAt;
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];
    /** @var array<int, array<string, mixed>> */
    public array $breadcrumbs = [];
    /** @var array<string, mixed>|null */
    public ?array $user = null;
    /** First exception reported by Laravel's handler while this trace was active. @var array<string, mixed>|null */
    public ?array $exception = null;
    /** @var array<string, int> */
    public array $queryFingerprints = [];
    /** @var array<string, int> */
    public array $queryTemplates = [];

    public function __construct(?string $traceparent = null)
    {
        $this->startedAt = microtime(true);
        $this->spanId = bin2hex(random_bytes(8));

        // Parse official W3C traceparent header: 00-{trace_id}-{parent_id}-{flags}
        if ($traceparent && preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', trim($traceparent), $matches)) {
            $this->traceId = strtolower($matches[2]);
            $this->parentSpanId = strtolower($matches[3]);
        } elseif ($traceparent && strlen(trim($traceparent)) === 32 && ctype_xdigit(trim($traceparent))) {
            $this->traceId = strtolower(trim($traceparent));
        } else {
            $this->traceId = bin2hex(random_bytes(16));
        }
    }

    /** @param array<string, mixed> $user */
    public function identify(array $user): void
    {
        $this->user = $user;
    }

    /** @param array<string, mixed> $span */
    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }

    /** @param array<string, mixed> $crumb */
    public function addBreadcrumb(array $crumb): void
    {
        $this->breadcrumbs[] = array_merge([
            'timestamp' => microtime(true),
        ], $crumb);
        // Bound in-flight breadcrumbs
        if (count($this->breadcrumbs) > 100) {
            array_shift($this->breadcrumbs);
        }
    }

    /** Generates an outgoing W3C traceparent header for distributed propagation */
    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-01', $this->traceId, $this->spanId);
    }
}
