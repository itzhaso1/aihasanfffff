<?php

declare(strict_types=1);

namespace App\AI\Tools;

final class ArgumentSecurity
{
    private const MAX_ARGUMENT_CHARS = 2000;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'workspace_id',
        'workspace',
        'tenant_id',
        'tenant',
        'organization_id',
        'customer_id',
        'conversation_id',
        'user_id',
        'message_id',
        'api_key',
        'token',
        'secret',
        'password',
        'authorization',
    ];

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function inspect(array $arguments): ?string
    {
        foreach (array_keys($arguments) as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized === '') {
                return ToolDenial::INVALID_ARGUMENTS;
            }
            if ($this->isForbidden($normalized)) {
                return ToolDenial::FORBIDDEN_ARGUMENT;
            }
        }

        foreach ($arguments as $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_ARGUMENT_CHARS) {
                return ToolDenial::ARGUMENT_TOO_LARGE;
            }
        }

        return null;
    }

    private function isForbidden(string $normalized): bool
    {
        if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        return str_ends_with($normalized, '_workspace_id')
            || str_ends_with($normalized, '_tenant_id')
            || str_ends_with($normalized, '_customer_id');
    }
}
