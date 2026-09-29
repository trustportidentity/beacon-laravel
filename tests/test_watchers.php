<?php

namespace Illuminate\Database\Events {
    class QueryExecuted {
        public string $sql;
        public array $bindings;
        public float $time;
        public string $connectionName;
        public function __construct(string $sql, array $bindings, float $time, string $conn = 'mysql') {
            $this->sql = $sql;
            $this->bindings = $bindings;
            $this->time = $time;
            $this->connectionName = $conn;
        }
    }
}

namespace Illuminate\Cache\Events {
    class CacheHit {
        public string $key;
        public array $tags = [];
        public function __construct(string $key) {
            $this->key = $key;
        }
    }
    class CacheMissed {
        public string $key;
        public array $tags = [];
        public function __construct(string $key) {
            $this->key = $key;
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/ActiveTrace.php';
    require_once __DIR__ . '/../src/BeaconClient.php';
    require_once __DIR__ . '/../src/BeaconManager.php';
    require_once __DIR__ . '/../src/Span.php';
    require_once __DIR__ . '/../src/Watchers/LogWatcher.php';
    require_once __DIR__ . '/../src/Watchers/QueryWatcher.php';
    require_once __DIR__ . '/../src/Watchers/CacheWatcher.php';

    use TrustPortIdentity\Beacon\ActiveTrace;
    use TrustPortIdentity\Beacon\BeaconClient;
    use TrustPortIdentity\Beacon\BeaconManager;
    use TrustPortIdentity\Beacon\Watchers\LogWatcher;
    use TrustPortIdentity\Beacon\Watchers\QueryWatcher;
    use TrustPortIdentity\Beacon\Watchers\CacheWatcher;
    use Illuminate\Database\Events\QueryExecuted;
    use Illuminate\Cache\Events\CacheHit;
    use Illuminate\Cache\Events\CacheMissed;

    echo "1. Testing W3C traceparent parsing...\n";
    $header = "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01";
    $trace = new ActiveTrace($header);

    assert($trace->traceId === '4bf92f3577b34da6a3ce929d0e0e4736', "Expected traceId to match");
    assert($trace->parentSpanId === '00f067aa0ba902b7', "Expected parentSpanId to match");
    assert(strlen($trace->spanId) === 16, "Expected 16-hex character spanId");
    assert(str_starts_with($trace->toTraceparent(), '00-4bf92f3577b34da6a3ce929d0e0e4736-'), "Expected valid outgoing traceparent");
    echo "   ✓ W3C Traceparent parser PASS\n";

    echo "2. Testing Breadcrumbs and LogWatcher PII Sanitization...\n";
    $client = new BeaconClient('test-key', 'laravel-app');
    $manager = new BeaconManager($client);
    $manager->setCurrentTrace($trace);

    $logWatcher = new LogWatcher($manager);

    $mockEvent = new class {
        public string $level = 'info';
        public string $message = 'User requested password reset';
        public array $context = [
            'user_id' => 123,
            'email' => 'alex@example.com',
            'password' => 'secret123',
            'auth_token' => 'bearer-abc',
            'nested' => [
                'api_key' => 'secret-key-123',
                'public_info' => 'ok',
            ],
        ];
    };

    $logWatcher->recordLog($mockEvent);

    assert(count($trace->breadcrumbs) === 1, "Expected 1 breadcrumb");
    $crumb = $trace->breadcrumbs[0];
    assert($crumb['message'] === 'User requested password reset');
    assert($crumb['context']['password'] === '[redacted]', "Expected password to be redacted");
    assert($crumb['context']['auth_token'] === '[redacted]', "Expected auth_token to be redacted");
    assert($crumb['context']['nested']['api_key'] === '[redacted]', "Expected nested api_key to be redacted");
    assert($crumb['context']['nested']['public_info'] === 'ok', "Expected public_info to remain");
    echo "   ✓ Breadcrumbs & PII redaction PASS\n";

    echo "3. Testing QueryWatcher DB::listen, Duplicate & N+1 Detection...\n";
    $queryWatcher = new QueryWatcher($manager, [
        'queries' => true,
        'detect_duplicates' => true,
        'detect_n_plus_one' => true,
        'slow_query_threshold_ms' => 50.0,
    ]);

    // 1st query: normal
    $queryWatcher->recordQuery(new QueryExecuted('select * from users where id = ?', [1], 5.2, 'mysql'));
    assert(count($trace->spans) === 1);
    assert($trace->spans[0]['type'] === 'database');
    assert($trace->spans[0]['duration_ms'] === 5.2);
    assert(!isset($trace->spans[0]['tags']['duplicate_query']), "1st query should not be duplicate");

    // 2nd query: duplicate with same bindings
    $queryWatcher->recordQuery(new QueryExecuted('select * from users where id = ?', [1], 4.8, 'mysql'));
    assert(count($trace->spans) === 2);
    assert(isset($trace->spans[1]['tags']['duplicate_query']), "2nd query must be flagged as duplicate");
    assert($trace->spans[1]['tags']['duplicate_count'] === '2');

    // 3rd query: same template with different bindings (N+1 query trigger!)
    $queryWatcher->recordQuery(new QueryExecuted('select * from users where id = ?', [2], 85.0, 'mysql'));
    assert(count($trace->spans) === 3);
    assert(isset($trace->spans[2]['tags']['n_plus_one_suspect']), "3rd execution must be flagged as N+1 suspect");
    assert($trace->spans[2]['tags']['template_occurrences'] === '3');
    assert(isset($trace->spans[2]['tags']['slow_query']), "85ms query must be flagged as slow (threshold 50ms)");
    echo "   ✓ QueryWatcher DB::listen, duplicate, slow query, and N+1 detection PASS\n";

    echo "4. Testing CacheWatcher Hit/Miss...\n";
    $cacheWatcher = new CacheWatcher($manager);
    $cacheWatcher->recordCacheHit(new CacheHit('user_cache_1'));
    $cacheWatcher->recordCacheMiss(new CacheMissed('user_cache_2'));

    assert(count($trace->spans) === 5);
    assert($trace->spans[3]['type'] === 'cache');
    assert($trace->spans[3]['metadata']['hit'] === true);
    assert($trace->spans[4]['type'] === 'cache');
    assert($trace->spans[4]['metadata']['hit'] === false);
    echo "   ✓ CacheWatcher Redis/Cache hit/miss PASS\n";

    echo "\n============================================\n";
    echo "✓ ALL LARAVEL NIGHTWATCH TESTS PASSED (100%)\n";
    echo "============================================\n";
}
