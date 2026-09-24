<?php

namespace App\Support\Network;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Blocks SSRF-prone mailbox hosts while allowing legitimate public mail servers.
 */
final class MailboxHostValidator
{
    /**
     * @throws ValidationException
     */
    public static function assertAllowed(string $host, string $attribute = 'host'): void
    {
        $host = self::normalizeHost($host);

        Validator::validate(
            [$attribute => $host],
            [$attribute => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-]+$/']],
        );

        if (self::isBlockedLiteral($host)) {
            throw ValidationException::withMessages([
                $attribute => [__('عنوان الخادم غير مسموح.')],
            ]);
        }

        $ips = self::resolveAll($host);

        if ($ips === []) {
            throw ValidationException::withMessages([
                $attribute => [__('تعذر التحقق من عنوان الخادم.')],
            ]);
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw ValidationException::withMessages([
                    $attribute => [__('عنوان الخادم يشير إلى شبكة غير مسموحة.')],
                ]);
            }
        }
    }

    public static function normalizeHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#^[a-z][a-z0-9+\-.]*://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];
        $host = explode(':', $host, 2)[0];

        return strtolower(trim($host, ". \t\n\r\0\x0B"));
    }

    private static function isBlockedLiteral(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isBlockedIp($host);
        }

        return in_array($host, [
            'metadata.google.internal',
            'metadata.goog',
        ], true);
    }

    /**
     * @return list<string>
     */
    private static function resolveAll(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = [];

        $ipv4 = dns_get_record($host, DNS_A);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $record) {
                if (! empty($record['ip'])) {
                    $records[] = $record['ip'];
                }
            }
        }

        $ipv6 = dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6)) {
            foreach ($ipv6 as $record) {
                if (! empty($record['ipv6'])) {
                    $records[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($records));
    }

    private static function isBlockedIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (in_array($ip, ['0.0.0.0', '127.0.0.1', '::1', '::'], true)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isPrivateIpv4($ip);
        }

        return self::isPrivateIpv6($ip);
    }

    private static function isPrivateIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['100.64.0.0', '100.127.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateIpv6(string $ip): bool
    {
        $lower = strtolower($ip);

        if ($lower === '::1' || str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
            return true;
        }

        if (str_starts_with($lower, '::ffff:')) {
            $mapped = substr($lower, 7);

            return filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? self::isPrivateIpv4($mapped)
                : true;
        }

        return false;
    }
}
