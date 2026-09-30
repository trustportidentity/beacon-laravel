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
        if ($this->shouldIgnore($request)) {
            return $next($request);
        }

        $trace = new ActiveTrace($request->header('traceparent'));
        $this->manager->setCurrentTrace($trace);
        $start = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // The user is resolved by auth middleware that runs inside $next, so read it now.
            $this->identifyUser($request, $trace);
            $this->report($request, $trace, $start, 500, [
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
            ]);
            $this->manager->setCurrentTrace(null);
            throw $e;
        }

        // Identify AFTER the request ran: token guards (Sanctum, Passport) authenticate inside route
        // middleware, so $request->user() is only available once $next() has returned.
        $this->identifyUser($request, $trace);

        // Attach outgoing W3C traceparent header to response for distributed continuity
        $response->headers->set('traceparent', $trace->toTraceparent());

        $this->report($request, $trace, $start, $response->getStatusCode(), null);
        $this->manager->setCurrentTrace(null);

        return $response;
    }

    /** Health checks and similar noise are not traced (config: beacon.ignore_paths). */
    private function shouldIgnore(Request $request): bool
    {
        $patterns = (array) config('beacon.ignore_paths', []);
        return $patterns !== [] && $request->is(...$patterns);
    }

    /** Attach the authenticated user, if any. Telemetry must never break a request. */
    private function identifyUser(Request $request, ActiveTrace $trace): void
    {
        try {
            $user = $request->user();
            if ($user === null) {
                return;
            }
            $userId = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : ($user->id ?? '');
            $trace->identify([
                'id' => (string) $userId,
                'email' => (string) ($user->email ?? ''),
                'username' => (string) ($user->name ?? $user->username ?? $userId),
            ]);
        } catch (Throwable) {
            // ignore
        }
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
