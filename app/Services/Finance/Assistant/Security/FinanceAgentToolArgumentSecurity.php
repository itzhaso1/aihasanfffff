<?php

namespace App\Services\Finance\Assistant\Security;

/**
 * Untrusted tool arguments. Identity overrides are removed and audited, never applied.
 */
final class FinanceAgentToolArgumentSecurity
{
    /** @var list<string> */
    private const FORBIDDEN = [
        'workspace',
        'workspace_id',
        'tenant',
        'tenant_id',
        'user',
        'user_id',
        'seller_id',
        'customer_id',
        'invoice_id',
        'payment_id',
        'account_id',
        'conversation_id',
        'role',
        'permission',
        'permissions',
        'previous_interaction_id',
        'interaction_id',
    ];

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{arguments: array<string, mixed>, stripped: list<string>, decision: ?FinanceAgentSecurityDecision}
     */
    public function sanitize(array $arguments): array
    {
        $decision = $this->assertBounds($arguments);
        if ($decision !== null) {
            return ['arguments' => [], 'stripped' => [], 'decision' => $decision];
        }

        $stripped = [];
        foreach (array_keys($arguments) as $key) {
            if (! is_string($key)) {
                unset($arguments[$key]);
                $stripped[] = 'non_string_key';

                continue;
            }

            $name = strtolower($key);
            if ($name === 'id' || str_ends_with($name, '_id') || in_array($name, self::FORBIDDEN, true)) {
                unset($arguments[$key]);
                $stripped[] = $key;
            }
        }

        return [
            'arguments' => $arguments,
            'stripped' => $stripped,
            'decision' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function assertBounds(array $arguments, int $depth = 0): ?FinanceAgentSecurityDecision
    {
        $maxDepth = max(1, (int) config('finance_assistant.security.max_argument_depth', 4));
        $maxKeys = max(1, (int) config('finance_assistant.security.max_argument_keys', 20));
        $maxString = max(32, (int) config('finance_assistant.security.max_argument_string', 200));

        if ($depth > $maxDepth) {
            return FinanceAgentSecurityDecision::invalid('argument_depth', 'Tool arguments are too deeply nested.');
        }

        if (count($arguments) > $maxKeys) {
            return FinanceAgentSecurityDecision::invalid('argument_size', 'Too many tool arguments.');
        }

        foreach ($arguments as $value) {
            if (is_string($value) && mb_strlen($value) > $maxString) {
                return FinanceAgentSecurityDecision::invalid('argument_string', 'A tool argument is too long.');
            }
            if (is_array($value)) {
                $nested = $this->assertBounds($value, $depth + 1);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
