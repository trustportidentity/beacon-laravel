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

        // Laravel turns exceptions into 500 responses inside the pipeline, so they never reach the catch above;
        // the handler hook (BeaconServiceProvider) stored them on the trace. A 5xx the app returned on purpose
        // (abort(500), response()->json(..., 503)) has no exception, so describe it from the response.
        $status = $response->getStatusCode();
        $exception = $trace->exception ?? ($status >= 500 ? $this->serverErrorException($request, $response) : null);
        $this->report($request, $trace, $start, $status, $exception);
        $this->manager->setCurrentTrace(null);

        return $response;
    }

    /**
     * Describes a 5xx response that carried no exception. The type names the status and the error message the
     * app sent back, so each distinct failure groups into its own issue.
     *
     * @return array<string, mixed>
     */
    private function serverErrorException(Request $request, Response $response): array
    {
        $status = $response->getStatusCode();
        $message = '';
        try {
            $body = $response->getContent();
            if (is_string($body) && $body !== '') {
                $body = substr($body, 0, 2048);
                $json = json_decode($body, true);
                if (is_array($json)) {
                    foreach (['message', 'error', 'detail', 'title'] as $key) {
                        if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                            $message = $json[$key];
                            break;
                        }
                    }
                } elseif (!str_contains(strtolower($response->headers->get('Content-Type', '')), 'html')) {
                    $message = trim($body);
                }
            }
        } catch (Throwable) {
            // Streamed and binary responses have no readable body.
        }
        $message = mb_substr($message, 0, 500);
        $type = "HTTP {$status}" . ($message !== '' ? ': ' . mb_substr($message, 0, 120) : '');
        return [
            'type' => $type,
            'message' => "{$request->method()} /{$request->path()} responded {$status}" . ($message !== '' ? ": {$message}" : ''),
            'handled' => true,
            'stacktrace' => [],
        ];
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
