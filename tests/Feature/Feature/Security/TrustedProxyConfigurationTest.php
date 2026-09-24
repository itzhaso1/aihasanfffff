<?php

namespace Tests\Feature\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__testing/proxy-inspect', function (Request $request) {
            return response()->json([
                'ip' => $request->ip(),
                'secure' => $request->secure(),
            ]);
        });

        Route::middleware('web')->get('/__testing/session-probe', function () {
            session(['probe' => true]);

            return response('ok');
        });
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        RateLimiter::clear('mobile-login');

        parent::tearDown();
    }

    public function test_trusted_proxy_request_uses_forwarded_client_ip(): void
    {
        TrustProxies::at(['10.0.0.1']);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ])->getJson('/__testing/proxy-inspect');

        $response->assertOk()
            ->assertJsonPath('ip', '198.51.100.10');
    }

    public function test_untrusted_request_ignores_spoofed_forwarded_ip(): void
    {
        TrustProxies::flushState();
        config(['trustedproxy.proxies' => null]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ])->getJson('/__testing/proxy-inspect');

        $response->assertOk()
            ->assertJsonPath('ip', '203.0.113.50');
    }

    public function test_https_forwarded_request_is_secure_from_trusted_proxy(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/__testing/proxy-inspect');

        $response->assertOk()->assertJsonPath('secure', true);
    }

    public function test_https_forwarded_request_is_not_secure_without_trusted_proxy(): void
    {
        TrustProxies::flushState();
        config(['trustedproxy.proxies' => null]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
        ])->getJson('/__testing/proxy-inspect');

        $response->assertOk()->assertJsonPath('secure', false);
    }

    public function test_session_cookie_is_secure_when_request_is_secure_via_trusted_proxy(): void
    {
        TrustProxies::at(['127.0.0.1']);
        config(['session.secure' => true]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
        ])->get('/__testing/session-probe');

        $response->assertOk();
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_contains($cookie->getName(), 'session'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_rate_limiter_uses_forwarded_client_ip_when_proxy_is_trusted(): void
    {
        TrustProxies::at(['10.0.0.1']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
            ])->postJson('/api/mobile/v1/auth/login', [
                'email' => 'proxy-rate-limit@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ])->postJson('/api/mobile/v1/auth/login', [
            'email' => 'proxy-rate-limit@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88',
        ])->postJson('/api/mobile/v1/auth/login', [
            'email' => 'proxy-rate-limit@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_rate_limiter_without_trusted_proxy_does_not_split_clients_by_forwarded_ip(): void
    {
        TrustProxies::flushState();
        config(['trustedproxy.proxies' => null]);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
            ])->postJson('/api/mobile/v1/auth/login', [
                'email' => 'proxy-rate-limit@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88',
        ])->postJson('/api/mobile/v1/auth/login', [
            'email' => 'proxy-rate-limit@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
