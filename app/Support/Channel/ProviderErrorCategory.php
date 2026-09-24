<?php

namespace App\Support\Channel;

final class ProviderErrorCategory
{
    public const AUTHENTICATION_FAILED = 'authentication_failed';

    public const PERMISSION_DENIED = 'permission_denied';

    public const RATE_LIMITED = 'rate_limited';

    public const INVALID_RECIPIENT = 'invalid_recipient';

    public const UNSUPPORTED_MESSAGE = 'unsupported_message';

    public const PROVIDER_TIMEOUT = 'provider_timeout';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const UNKNOWN = 'unknown_provider_error';

    public static function fromHttpStatus(int $status): string
    {
        return match (true) {
            $status === 401 => self::AUTHENTICATION_FAILED,
            $status === 403 => self::PERMISSION_DENIED,
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 => self::PROVIDER_UNAVAILABLE,
            $status >= 400 => self::INVALID_RECIPIENT,
            default => self::UNKNOWN,
        };
    }
}
