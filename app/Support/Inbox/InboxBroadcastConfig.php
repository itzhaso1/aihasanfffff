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
        ];
    }
}
