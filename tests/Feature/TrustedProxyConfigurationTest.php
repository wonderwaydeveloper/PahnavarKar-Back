<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_only_configured_proxies_can_forward_the_client_ip(): void
    {
        config(['trustedproxy.proxies' => ['10.20.0.10']]);

        $trustedRequest = Request::create('http://api.example.test/health', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.20.0.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.24',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example.test',
        ]);

        app(TrustProxies::class)->handle($trustedRequest, fn (Request $request) => $request->getClientIp());

        $this->assertSame('198.51.100.24', $trustedRequest->getClientIp());
        $this->assertSame('https', $trustedRequest->getScheme());
        $this->assertSame('api.example.test', $trustedRequest->getHost());

        $untrustedRequest = Request::create('http://api.example.test/health', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.20.0.11',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.24',
        ]);

        app(TrustProxies::class)->handle($untrustedRequest, fn (Request $request) => $request->getClientIp());

        $this->assertSame('10.20.0.11', $untrustedRequest->getClientIp());
    }
}
