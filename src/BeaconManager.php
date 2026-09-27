<?php

namespace TrustPortIdentity\Beacon;

/**
 * Holds the currently-active trace for the request being handled (set by
 * BeaconMiddleware) and exposes the span/identify helpers the Beacon facade calls.
 */
class BeaconManager
{
    private BeaconClient $client;
    private ?ActiveTrace $currentTrace = null;

    public function __construct(BeaconClient $client)
    {
        $this->client = $client;
    }

    public function client(): BeaconClient
    {
        return $this->client;
    }

    public function setCurrentTrace(?ActiveTrace $trace): void
    {
        $this->currentTrace = $trace;
    }

    public function currentTrace(): ?ActiveTrace
    {
        return $this->currentTrace;
    }

    public function span(string $name, string $type = 'custom', ?array $metadata = null): Span
    {
        $trace = $this->currentTrace ?? new ActiveTrace();
        return $this->client->newSpan($trace, $name, $type, $metadata);
    }

    /** @param array<string, mixed> $user */
    public function identify(array $user): void
    {
        $this->currentTrace?->identify($user);
    }
}
