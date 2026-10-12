<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;

final class CorrelationContext
{
    private static ?string $id = null;

    public static function set(?string $id): void
    {
        self::$id = is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    public static function id(): string
    {
        if (self::$id === null || self::$id === '') {
            self::$id = (string) Str::uuid();
        }

        return self::$id;
    }

    /**
     * @return array{correlation_id: string}
     */
    public static function logContext(): array
    {
        return ['correlation_id' => self::id()];
    }

    public static function reset(): void
    {
        self::$id = null;
    }
}
