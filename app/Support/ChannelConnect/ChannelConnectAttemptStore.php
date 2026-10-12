<?php

namespace App\Support\ChannelConnect;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

final class ChannelConnectAttemptStore
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function issue(string $purpose, array $payload, ?int $ttlMinutes = null): string
    {
        $token = Str::random(64);
        $minutes = $ttlMinutes ?? (int) config('channel_connect.attempt_ttl_minutes', 15);

        Cache::put(
            $this->key($purpose, $token),
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            now()->addMinutes(max(1, $minutes)),
        );

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $purpose, string $token): ?array
    {
        return $this->decode(Cache::get($this->key($purpose, $token)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pull(string $purpose, string $token): ?array
    {
        return $this->decode(Cache::pull($this->key($purpose, $token)));
    }

    private function key(string $purpose, string $token): string
    {
        return 'channel_connect:'.$purpose.':'.$token;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|DecryptException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
