<?php

namespace Tests\Unit\Support\Http;

use App\Support\Http\TrustedProxyConfiguration;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_empty_proxy_string_returns_null(): void
    {
        $this->assertNull(TrustedProxyConfiguration::proxies(null));
        $this->assertNull(TrustedProxyConfiguration::proxies(''));
        $this->assertNull(TrustedProxyConfiguration::proxies('   '));
    }

    public function test_single_proxy_value_is_returned_as_string(): void
    {
        $this->assertSame('127.0.0.1', TrustedProxyConfiguration::proxies('127.0.0.1'));
        $this->assertSame('*', TrustedProxyConfiguration::proxies('*'));
    }

    public function test_comma_separated_proxies_are_parsed(): void
    {
        $this->assertSame(
            ['127.0.0.1', '::1', 'REMOTE_ADDR'],
            TrustedProxyConfiguration::proxies('127.0.0.1, ::1 , REMOTE_ADDR')
        );
    }

    #[DataProvider('headerProvider')]
    public function test_headers_bitmask(string $configured, int $expected): void
    {
        $this->assertSame($expected, TrustedProxyConfiguration::headers($configured));
    }

    public static function headerProvider(): array
    {
        return [
            'standard' => ['standard', Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO],
            'aws_elb' => ['aws_elb', Request::HEADER_X_FORWARDED_AWS_ELB],
            'forwarded' => ['forwarded', Request::HEADER_FORWARDED],
        ];
    }
}
