<?php

namespace TrustPortIdentity\Beacon;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BeaconMiddleware
{
    public function __construct(
        private readonly BeaconClient $client,
        private readonly BeaconManager $manager
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $trace = new ActiveTrace($request->header('traceparent'));
        $this->manager->setCurrentTrace($trace);
        $start = microtime(true);

        $exception = null;
        try {
            // Automatically capture authenticated user context if logged in
            $user = $request->user();
            if ($user !== null) {
                $userId = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : ($user->id ?? '');
                $trace->identify([
                    'id' => (string) $userId,
                    'email' => (string) ($user->email ?? ''),
                    'username' => (string) ($user->name ?? $user->username ?? $userId),
                ]);
            }

            $response = $next($request);
            $statusCode = $response->getStatusCode();
        } catch (Throwable $e) {
            $statusCode = 500;
            $exception = [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'handled' => false,
                'stacktrace' => array_map(
                    fn (array $frame) => [
                        'file' => $frame['file'] ?? 'unknown',
                        'line' => $frame['line'] ?? 0,
                        'function' => $frame['function'] ?? 'unknown',
                    ],
                    $e->getTrace()
                ),
            ];
            $this->report($request, $trace, $start, $statusCode, $exception);
            $this->manager->setCurrentTrace(null);
            throw $e;
        }

        // Attach outgoing W3C traceparent header to response for distributed continuity
        $response->headers->set('traceparent', $trace->toTraceparent());

        $this->report($request, $trace, $start, $statusCode, $exception);
        $this->manager->setCurrentTrace(null);

        return $response;
    }

    /** @param array<string, mixed>|null $exception */
    private function report(Request $request, ActiveTrace $trace, float $start, int $statusCode, ?array $exception): void
    {
        $durationMs = (microtime(true) - $start) * 1000;

        $this->client->report(
            $trace,
            [
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'url' => $request->fullUrl(),
                'status_code' => $statusCode,
                'headers' => $this->flattenHeaders($request->headers->all()),
                'client_ip' => $request->ip(),
            ],
            $durationMs,
            $exception
        );
    }

    /** @param array<string, array<int, string>> $headers @return array<string, string> */
    private function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = $v[0] ?? '';
        }
        return $out;
    }
}
