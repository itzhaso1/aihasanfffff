<?php

namespace App\Services\AI\Inbox\Security;

final class InboxAgentToolArgumentSecurity
{
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

    public function __construct(
        private readonly InboxAgentSecurityPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function assertSafe(array $arguments): ?InboxAgentSecurityDecision
    {
        foreach (array_keys($arguments) as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized === '') {
                return InboxAgentSecurityDecision::invalid('invalid_argument_key', 'Tool arguments are invalid.');
            }

            if ($this->isForbiddenKey($normalized)) {
                return InboxAgentSecurityDecision::deny(
                    'forbidden_identity_argument',
                    'Tool arguments included forbidden identity fields.',
                );
            }
        }

        foreach ($arguments as $key => $value) {
            if (is_string($value) && mb_strlen($value) > $this->policy->maxToolArgumentChars()) {
                return InboxAgentSecurityDecision::invalid(
                    'argument_too_large',
                    'Tool argument exceeds allowed size.',
                );
            }
        }

        return null;
    }

    private function isForbiddenKey(string $normalized): bool
    {
        if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        return str_ends_with($normalized, '_workspace_id')
            || str_ends_with($normalized, '_tenant_id')
            || str_ends_with($normalized, '_customer_id');
    }
}
