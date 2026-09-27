<?php

namespace TrustPortIdentity\Beacon;

class Span
{
    private ActiveTrace $trace;
    private string $type;
    private string $name;
    /** @var array<string, mixed>|null */
    private ?array $metadata;
    private float $startedAt;
    /** @var array<string, string> */
    private array $tags = [];

    public function __construct(ActiveTrace $trace, string $type, string $name, ?array $metadata = null)
    {
        $this->trace = $trace;
        $this->type = $type;
        $this->name = $name;
        $this->metadata = $metadata;
        $this->startedAt = microtime(true);
    }

    public function setTag(string $key, mixed $value): static
    {
        $this->tags[$key] = (string) $value;
        return $this;
    }

    public function end(): void
    {
        $durationMs = (microtime(true) - $this->startedAt) * 1000;
        $startMs = max(0.0, ($this->startedAt - $this->trace->startedAt) * 1000);

        $this->trace->spans[] = [
            'type' => $this->type,
            'name' => $this->name,
            'start_ms' => round($startMs, 2),
            'duration_ms' => round($durationMs, 2),
            'metadata' => $this->metadata,
            'tags' => $this->tags ?: null,
        ];
    }
}
