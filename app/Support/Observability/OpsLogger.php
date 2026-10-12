<?php

namespace App\Support\Observability;

use App\Services\Backup\SecretRedactor;
use Illuminate\Support\Facades\Log;

final class OpsLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $event, array $context): void
    {
        $payload = array_merge(
            ['event' => $event],
            CorrelationContext::logContext(),
            self::sanitizeContext($context),
        );

        Log::log($level, $event, $payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $forbiddenKeys = [
            'authorization', 'password', 'token', 'access_token', 'app_key',
            'payload', 'headers', 'raw', 'messages', 'prompt', 'content',
        ];

        $redactor = app(SecretRedactor::class);
        $safe = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $forbiddenKeys, true)) {
                continue;
            }

            if (is_string($value)) {
                $safe[$key] = $redactor->redact($value, self::secretValues());

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return list<string>
     */
    private static function secretValues(): array
    {
        return array_values(array_filter([
            (string) config('database.redis.default.password'),
            (string) config('database.connections.mysql.password'),
            (string) config('services.whatsapp.token'),
            (string) config('app.key'),
        ], fn (string $v): bool => $v !== '' && $v !== 'null'));
    }
}
