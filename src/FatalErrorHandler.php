<?php

namespace TrustPortIdentity\Beacon;

/**
 * Reports PHP fatal errors (memory exhausted, max execution time, parse/compile errors) that kill the process
 * before Laravel can handle them. Runs from a shutdown function and flushes straight away.
 */
class FatalErrorHandler
{
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    private static bool $registered = false;
    /** Freed at shutdown so there is memory left to build the report after "memory exhausted". */
    private static ?string $reserve = null;

    public static function register(BeaconClient $client, BeaconManager $manager): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        self::$reserve = str_repeat('x', 256 * 1024);
        register_shutdown_function(static function () use ($client, $manager) {
            self::$reserve = null;
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], self::FATAL, true)) {
                return;
            }
            self::report($client, $manager, $error);
        });
    }

    /** @param array{type:int,message:string,file:string,line:int} $error */
    public static function report(BeaconClient $client, BeaconManager $manager, array $error): void
    {
        try {
            $trace = $manager->getCurrentTrace() ?? new ActiveTrace();
            $isHttp = PHP_SAPI !== 'cli';
            $client->report($trace, [
                'method' => $isHttp ? ($_SERVER['REQUEST_METHOD'] ?? 'GET') : 'CLI',
                'route' => $isHttp ? strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') : 'console:' . basename((string) ($_SERVER['argv'][1] ?? 'script')),
                'url' => $isHttp ? (($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/')) : 'cli://' . ($_SERVER['argv'][1] ?? 'script'),
                'status_code' => 500,
            ], (microtime(true) - $trace->startedAt) * 1000, [
                'type' => 'FatalError',
                'message' => $error['message'],
                'handled' => false,
                'stacktrace' => [['file' => $error['file'], 'line' => $error['line'], 'function' => 'fatal']],
            ]);
            $client->flush();
        } catch (\Throwable) {
            // nothing more can be done during shutdown
        }
    }
}
