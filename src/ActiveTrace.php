<?php

namespace TrustPortIdentity\Beacon;

class ActiveTrace
{
    public string $traceId;
    public float $startedAt;
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];
    /** @var array<string, mixed>|null */
    public ?array $user = null;

    public function __construct(?string $traceId = null)
    {
        $this->traceId = $traceId ?: bin2hex(random_bytes(16));
        $this->startedAt = microtime(true);
    }

    /** @param array<string, mixed> $user */
    public function identify(array $user): void
    {
        $this->user = $user;
    }
}
