<?php

namespace TrustPortIdentity\Beacon;

/**
 * Core Beacon client: batches trace events and ships them to the ingest endpoint.
 * Framework-agnostic; BeaconServiceProvider wires this up for Laravel.
 */
class BeaconClient
{
    private string $ingestUrl;
    private string $apiKey;
    private string $serviceName;
    private string $environment;
    private int $batchSize;
    private bool $sanitizePii;
    private float $sampleRate;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie'];
    private const CARD_NUMBER_PATTERN = '/\b(?:\d[ -]*?){13,19}\b/';

    public function __construct(
        string $apiKey,
        string $serviceName,
        string $ingestUrl = 'http://localhost:8443',
        string $environment = 'production',
        int $batchSize = 50,
        bool $sanitizePii = false,
        float $sampleRate = 1.0
    ) {
        $this->apiKey = $apiKey;
        $this->serviceName = $serviceName;
        $this->ingestUrl = rtrim($ingestUrl, '/');
        $this->environment = $environment;
        $this->batchSize = $batchSize;
        $this->sanitizePii = $sanitizePii;
        $this->sampleRate = ($sampleRate > 0 && $sampleRate <= 1) ? $sampleRate : 1.0;
    }

    /**
     * Exceptions are always sent regardless of sampleRate - sampling controls ingest
     * volume for routine traffic, never error visibility.
     */
    private function shouldSample(bool $hasException): bool
    {
        if ($hasException || $this->sampleRate >= 1.0) {
            return true;
        }
        return (mt_rand() / mt_getrandmax()) < $this->sampleRate;
    }

    public function newSpan(ActiveTrace $trace, string $name, string $type = 'custom', ?array $metadata = null): Span
    {
        return new Span($trace, $type, $name, $metadata);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $exception
     */
    public function report(ActiveTrace $trace, array $request, float $durationMs, ?array $exception = null): void
    {
        if (!$this->shouldSample($exception !== null)) {
            return;
        }

        if ($this->sanitizePii) {
            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = $this->sanitizeHeaders($request['headers']);
            }
            if (isset($request['url'])) {
                $request['url'] = $this->sanitizeString($request['url']);
            }
        }

        $event = [
            'id' => $trace->traceId,
            'project_key' => $this->apiKey,
            'service_name' => $this->serviceName,
            'environment' => $this->environment,
            'runtime' => 'php-' . PHP_VERSION,
            'trace_id' => $trace->traceId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => round($durationMs, 2),
            'user' => $trace->user,
            'request' => $request,
            'spans' => $trace->spans,
            'has_exception' => $exception !== null,
            'exception' => $exception,
        ];

        $this->queue[] = $event;
        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }
        $batch = $this->queue;
        $this->queue = [];

        $body = json_encode($batch);
        if ($body === false) {
            return;
        }

        $ch = curl_init("{$this->ingestUrl}/v1/batch");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Beacon-Key: ' . $this->apiKey,
                'User-Agent: trustportidentity-beacon-laravel/1.0',
            ],
            CURLOPT_TIMEOUT => 3,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
        // Deliberately ignore transport failures — telemetry must never break the host app.
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function sanitizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = in_array(strtolower($k), self::SENSITIVE_HEADERS, true) ? '[redacted]' : $v;
        }
        return $out;
    }

    private function sanitizeString(string $value): string
    {
        return preg_replace(self::CARD_NUMBER_PATTERN, '[redacted-card]', $value) ?? $value;
    }
}
