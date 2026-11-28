<?php

namespace App\Support\Inbox;

final class InboxBroadcastConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function clientPayload(string $authPath = '/api/broadcasting/auth'): array
    {
        $driver = (string) config('broadcasting.default', 'null');
        if (! in_array($driver, ['reverb', 'pusher'], true)) {
            $inboxDriver = config('inbox.realtime.broadcast_connection');
            if (is_string($inboxDriver) && in_array($inboxDriver, ['reverb', 'pusher'], true)) {
                $driver = $inboxDriver;
            }
        }
        if (! in_array($driver, ['reverb', 'pusher'], true)) {
            return [
                'enabled' => false,
                'driver' => $driver,
            ];
        }

        $connection = config('broadcasting.connections.'.$driver);
        if (! is_array($connection)) {
            return ['enabled' => false, 'driver' => $driver];
        }

        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $key = is_string($connection['key'] ?? null) ? trim($connection['key']) : '';

        if ($key === '') {
            return ['enabled' => false, 'driver' => $driver];
        }

        $activityTimeout = max(20, min(120, (int) config('reverb.apps.apps.0.activity_timeout', 30)));
        $configuredPing = (int) config('reverb.apps.apps.0.ping_interval', 20);
        // Keep client heartbeats strictly shorter than Reverb's activity_timeout and mobile NAT timeouts.
        $pingInterval = max(12, min($configuredPing > 0 ? $configuredPing : 20, max(12, $activityTimeout - 5), 25));

        return [
            'enabled' => true,
            'driver' => $driver,
            'key' => $key,
            'host' => $options['host'] ?? null,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => $options['scheme'] ?? 'https',
            'use_tls' => (bool) ($options['useTLS'] ?? true),
            'auth_endpoint' => url($authPath),
            'channel_prefix' => 'private-workspace.',
            'ping_interval' => $pingInterval,
            'activity_timeout' => $activityTimeout,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
