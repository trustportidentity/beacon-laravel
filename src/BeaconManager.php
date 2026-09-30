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

    public function getCurrentTrace(): ?ActiveTrace
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

    /** @return array<string, mixed> */
    public static function describe(\Throwable $e, bool $handled = false): array
    {
        return [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'handled' => $handled,
            'stacktrace' => array_map(
                fn (array $frame) => [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? 'unknown',
                ],
                array_slice([['file' => $e->getFile(), 'line' => $e->getLine()]] + $e->getTrace(), 0, 50)
            ),
        ];
    }

    /**
     * Called for every exception Laravel's handler decides to report (so validation/404/auth exceptions the
     * app ignores stay ignored). With an active request/job trace the exception is attached to that trace;
     * otherwise (artisan, scheduler, console) it is sent as its own trace so it is never lost.
     */
    public function recordException(\Throwable $e): void
    {
        try {
            $trace = $this->currentTrace;
            if ($trace !== null) {
                $trace->exception ??= self::describe($e);
                return;
            }
            $trace = new ActiveTrace();
            $this->client->report($trace, [
                'method' => 'CLI',
                'route' => 'console:' . basename((string) ($_SERVER['argv'][1] ?? 'script')),
                'url' => 'cli://' . (string) ($_SERVER['argv'][1] ?? 'script'),
                'status_code' => 500,
            ], 0.0, self::describe($e));
        } catch (\Throwable) {
            // telemetry must never break the host app
        }
    }
}
