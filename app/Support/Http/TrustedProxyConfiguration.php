<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

final class TrustedProxyConfiguration
{
    /**
     * @return array<int, string>|string|null
     */
    public static function proxies(?string $configured): array|string|null
    {
        $configured = trim((string) $configured);
        if ($configured === '') {
            return null;
        }

        if (! str_contains($configured, ',')) {
            return $configured;
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    public static function headers(?string $configured = null): int
    {
        return match (trim((string) ($configured ?? config('trustedproxy.headers', 'standard')))) {
            'aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'forwarded' => Request::HEADER_FORWARDED,
            default => Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        };
    }
}
