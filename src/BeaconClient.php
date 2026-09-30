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

    private const SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-xsrf-token', 'x-csrf-token', 'x-auth-token',
    ];
    // Parameter names that hold secrets: *token*, *secret*, *password*, api key, auth, and names that ARE a key
    // (key, cron_key, app-key) or a signature/credential/session id. Harmless names like keyword/page are kept.
    private const SENSITIVE_PARAM_PATTERN = '/(token|secret|password|passwd|api_?key|auth|signature|credential|passphrase|session|(^|[_\-.])key$|^sig$|^jwt$|^otp$|^pin$|^cvv$)/i';
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

        // Secrets never leave the app: sensitive headers and query parameters are ALWAYS redacted.
        // sanitizePii additionally scrubs card-number-like values.
        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = $this->sanitizeHeaders($request['headers']);
        }
        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = $this->redactQuery($request['url']);
            if ($this->sanitizePii) {
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
            'parent_span' => $trace->parentSpanId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => round($durationMs, 2),
            'user' => $trace->user,
            'request' => $request,
            'spans' => $trace->spans,
            'breadcrumbs' => $trace->breadcrumbs,
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

    /** Redacts the values of sensitive query parameters (cron_key, token, signature, ...). */
    private function redactQuery(string $url): string
    {
        $pos = strpos($url, '?');
        if ($pos === false) {
            return $url;
        }
        $fragment = '';
        $hash = strpos($url, '#', $pos);
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $parts = explode('&', substr($url, $pos + 1));
        foreach ($parts as $i => $part) {
            $eq = strpos($part, '=');
            $name = urldecode($eq === false ? $part : substr($part, 0, $eq));
            if ($eq !== false && preg_match(self::SENSITIVE_PARAM_PATTERN, $name) === 1) {
                $parts[$i] = substr($part, 0, $eq) . '=[Filtered]';
            }
        }
        return substr($url, 0, $pos + 1) . implode('&', $parts) . $fragment;
    }

    private function sanitizeString(string $value): string
    {
        return preg_replace(self::CARD_NUMBER_PATTERN, '[redacted-card]', $value) ?? $value;
    }
}
