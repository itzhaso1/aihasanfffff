<?php

namespace App\Support\Http;

final class WebhookHeaderSanitizer
{
    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function redact(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string) $name);
            if (self::isSensitive($normalized)) {
                $redacted[$name] = '[redacted]';

                continue;
            }

            $redacted[$name] = $value;
        }

        return $redacted;
    }

    private static function isSensitive(string $normalizedName): bool
    {
        if (in_array($normalizedName, [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-hub-signature',
            'x-hub-signature-256',
            'x-webhook-signature',
        ], true)) {
            return true;
        }

        return str_contains($normalizedName, 'token')
            || str_contains($normalizedName, 'secret')
            || str_contains($normalizedName, 'password');
    }
}
