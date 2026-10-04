<?php

namespace TrustPortIdentity\Beacon\Tests;

use PHPUnit\Framework\TestCase;
use TrustPortIdentity\Beacon\ActiveTrace;
use TrustPortIdentity\Beacon\BeaconClient;

class SamplingTest extends TestCase
{
    /** Reports $runs requests with $status through a client sampling ~0% and returns how many were queued. */
    private function queuedCount(int $status, int $alwaysSendFromStatus = 500, int $runs = 200): int
    {
        $client = new class ('tb_live_x', 'app', 'http://127.0.0.1:1', 'production', 100000, false, 0.000001, $alwaysSendFromStatus) extends BeaconClient {
            public function queued(): array
            {
                $r = new \ReflectionProperty(BeaconClient::class, 'queue');
                $r->setAccessible(true);
                return $r->getValue($this);
            }
        };
        for ($i = 0; $i < $runs; $i++) {
            $client->report(new ActiveTrace(), ['status_code' => $status, 'url' => 'https://x.test/api/public/x'], 1.0);
        }
        return count($client->queued());
    }

    public function test_server_errors_are_always_sent_even_when_sampling_almost_everything_away(): void
    {
        $this->assertSame(200, $this->queuedCount(500));
        $this->assertSame(200, $this->queuedCount(503));
    }

    public function test_routine_responses_are_still_sampled(): void
    {
        $this->assertLessThan(5, $this->queuedCount(200));
        $this->assertLessThan(5, $this->queuedCount(404));
    }

    public function test_client_errors_can_be_kept_too(): void
    {
        $this->assertSame(200, $this->queuedCount(422, 400));
        $this->assertLessThan(5, $this->queuedCount(200, 400));
    }
}
