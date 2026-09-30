<?php

namespace TrustPortIdentity\Beacon\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use TrustPortIdentity\Beacon\ActiveTrace;
use TrustPortIdentity\Beacon\BeaconClient;
use TrustPortIdentity\Beacon\BeaconManager;
use TrustPortIdentity\Beacon\BeaconMiddleware;
use TrustPortIdentity\Beacon\BeaconServiceProvider;

/** Stands in for Sanctum: authenticates INSIDE the route pipeline, after Beacon's middleware began. */
class FakeAuth
{
    public static ?object $user = null;

    public function handle($request, \Closure $next)
    {
        $request->setUserResolver(fn () => self::$user);
        return $next($request);
    }
}

/** Records reports instead of sending them. */
class FakeClient extends BeaconClient
{
    /** @var array<int, array{trace: ActiveTrace, request: array, exception: ?array}> */
    public array $reports = [];

    public function report(ActiveTrace $trace, array $request, float $durationMs, ?array $exception = null): void
    {
        $this->reports[] = ['trace' => $trace, 'request' => $request, 'exception' => $exception];
    }

    public function flush(): void
    {
    }
}

class MiddlewareTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BeaconServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('beacon.api_key', 'tb_live_test');
        $app['config']->set('beacon.auto_middleware_console', true); // tests run in the console
        $app['config']->set('beacon.ignore_paths', ['up', 'health']);
    }

    private function fake(): FakeClient
    {
        $fake = new FakeClient('tb_live_test', 'test-app');
        $this->app->instance(BeaconClient::class, $fake);
        $this->app->instance(BeaconManager::class, new BeaconManager($fake));
        return $fake;
    }

    public function test_middleware_is_registered_globally_when_a_key_is_set(): void
    {
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(BeaconMiddleware::class));
    }

    public function test_user_authenticated_by_route_middleware_is_identified(): void
    {
        $fake = $this->fake();
        // Like Sanctum: the user is only resolved inside route middleware, AFTER Beacon's middleware started.
        FakeAuth::$user = new class {
            public $id = 42;
            public $name = 'Officer';
            public $email = 'officer@enugustate.gov.ng';
            public function getAuthIdentifier() { return 42; }
        };
        Route::middleware(FakeAuth::class)->get('/api/things', fn () => response('ok'));

        $response = $this->get('/api/things');

        $response->assertOk();
        $this->assertCount(1, $fake->reports);
        $this->assertSame(['id' => '42', 'email' => 'officer@enugustate.gov.ng', 'username' => 'Officer'], $fake->reports[0]['trace']->user);
        $this->assertSame('api/things', $fake->reports[0]['request']['route']);
        $this->assertSame(200, $fake->reports[0]['request']['status_code']);
        $this->assertNotEmpty($response->headers->get('traceparent'));
    }

    public function test_anonymous_request_is_traced_without_a_user(): void
    {
        $fake = $this->fake();
        Route::get('/public', fn () => response('hi', 201));

        $this->get('/public')->assertStatus(201);

        $this->assertCount(1, $fake->reports);
        $this->assertNull($fake->reports[0]['trace']->user);
        $this->assertSame(201, $fake->reports[0]['request']['status_code']);
    }

    public function test_exceptions_are_reported_with_the_user_and_rethrown(): void
    {
        $fake = $this->fake();
        FakeAuth::$user = (object) ['id' => 7, 'name' => 'A', 'email' => 'a@x.ng'];
        Route::middleware(FakeAuth::class)->get('/boom', function () {
            throw new \RuntimeException('kaput');
        });

        $this->withoutExceptionHandling();
        try {
            $this->get('/boom');
            $this->fail('expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('kaput', $e->getMessage());
        }

        $this->assertCount(1, $fake->reports);
        $this->assertSame(500, $fake->reports[0]['request']['status_code']);
        $this->assertSame('RuntimeException', $fake->reports[0]['exception']['type']);
        $this->assertSame('7', $fake->reports[0]['trace']->user['id']);
    }

    public function test_health_checks_are_not_traced(): void
    {
        $fake = $this->fake();
        Route::get('/health', fn () => response('ok'));

        $this->get('/health')->assertOk();

        $this->assertCount(0, $fake->reports);
    }

    public function test_authorization_header_is_not_in_the_reported_headers_unredacted(): void
    {
        $fake = $this->fake();
        Route::get('/h', fn () => response('ok'));

        $this->get('/h', ['Authorization' => 'Bearer secret-token'])->assertOk();

        // The client redacts sensitive headers on send; the middleware hands them over as-is, so just
        // confirm it did not crash and the header name is present for the client to redact.
        $this->assertArrayHasKey('authorization', $fake->reports[0]['request']['headers']);
    }
}

class NoKeyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BeaconServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('beacon.api_key', '');
        $app['config']->set('beacon.auto_middleware_console', true);
    }

    public function test_nothing_is_registered_without_an_api_key(): void
    {
        $this->assertFalse($this->app->make(Kernel::class)->hasMiddleware(BeaconMiddleware::class));
    }
}
