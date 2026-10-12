<?php

declare(strict_types=1);

namespace App\AI\Models;

use RuntimeException;

final class ModelGatewayException extends RuntimeException
{
    public const SETTINGS_NOT_FOUND = 'settings_not_found';

    public const PROVIDER_NOT_ALLOWED = 'provider_not_allowed';

    public const MODEL_NOT_ALLOWED = 'model_not_allowed';

    public const UNSUPPORTED_PROVIDER = 'unsupported_provider';

    public const MISSING_API_KEY = 'missing_api_key';

    public const HTTP_ERROR = 'http_error';

    public const RATE_LIMITED = 'rate_limited';

    public const TIMEOUT = 'timeout';

    public const EMPTY_RESPONSE = 'empty_response';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function settingsNotFound(int $employeeId): self
    {
        return new self(self::SETTINGS_NOT_FOUND, 'Employee model settings were not found.');
    }

    public static function providerNotAllowed(string $provider): self
    {
        return new self(self::PROVIDER_NOT_ALLOWED, 'Provider is not allowed.');
    }

    public static function modelNotAllowed(string $provider, string $model): self
    {
        return new self(self::MODEL_NOT_ALLOWED, 'Model is not allowed for the provider.');
    }

    public static function unsupportedProvider(string $provider): self
    {
        return new self(self::UNSUPPORTED_PROVIDER, 'No adapter is registered for this provider.');
    }

    public static function missingApiKey(string $provider): self
    {
        return new self(self::MISSING_API_KEY, 'The provider API key is not configured.');
    }

    public static function httpError(string $provider, int $status): self
    {
        return new self(self::HTTP_ERROR, 'The model provider request failed.', $status);
    }

    public static function rateLimited(string $provider): self
    {
        return new self(self::RATE_LIMITED, 'The model provider is rate limited.', 429);
    }

    public static function timeout(string $provider): self
    {
        return new self(self::TIMEOUT, 'The model provider timed out.');
    }

    public static function emptyResponse(): self
    {
        return new self(self::EMPTY_RESPONSE, 'The model provider returned no usable response.');
    }
}
