<?php

namespace TrustPortIdentity\Beacon\Tests;

use PHPUnit\Framework\TestCase;
use TrustPortIdentity\Beacon\ActiveTrace;
use TrustPortIdentity\Beacon\BeaconClient;

class ClientRedactionTest extends TestCase
{
    private function sent(array $request): array
    {
        // sanitizePii is OFF (the default): redaction of secrets must still happen.
        $client = new class ('tb_live_x', 'app', 'http://127.0.0.1:1') extends BeaconClient {
            public function queued(): array
            {
                $r = new \ReflectionProperty(BeaconClient::class, 'queue');
                $r->setAccessible(true);
                return $r->getValue($this);
            }
        };
        $client->report(new ActiveTrace(), $request, 1.0);
        return $client->queued()[0]['request'];
    }

    public function test_secret_query_params_and_headers_are_always_redacted(): void
    {
        $r = $this->sent([
            'url' => 'https://x.test/api/send_mails?cron_key=95c9cc78815947ba&page=2&keyword=cat&api_key=abc#frag',
            'headers' => ['authorization' => 'Bearer secret', 'cookie' => 'a=b', 'x-xsrf-token' => 't', 'accept' => 'application/json'],
        ]);
        $this->assertStringNotContainsString('95c9cc78815947ba', $r['url']);
        $this->assertStringNotContainsString('abc', $r['url']);
        $this->assertStringContainsString('page=2', $r['url']);
        $this->assertStringContainsString('keyword=cat', $r['url']);
        $this->assertStringEndsWith('#frag', $r['url']);
        $this->assertSame('[redacted]', $r['headers']['authorization']);
        $this->assertSame('[redacted]', $r['headers']['cookie']);
        $this->assertSame('[redacted]', $r['headers']['x-xsrf-token']);
        $this->assertSame('application/json', $r['headers']['accept']);
    }

    public function test_urls_without_a_query_string_are_untouched(): void
    {
        $this->assertSame('https://x.test/a/b', $this->sent(['url' => 'https://x.test/a/b'])['url']);
    }
}
