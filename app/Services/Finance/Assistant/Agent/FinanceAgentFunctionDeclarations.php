<?php

namespace App\Services\Finance\Assistant\Agent;

use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolRegistry;

final class FinanceAgentFunctionDeclarations
{
    public function __construct(
        private readonly FinanceAssistantToolRegistry $registry,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        $declarations = [];
        foreach ($this->registry->all() as $tool) {
            $declarations[] = $this->declaration($tool);
        }

        return $declarations;
    }

    public function geminiName(string $internalName): string
    {
        return str_replace('.', '_', $internalName);
    }

    public function internalName(string $geminiName): string
    {
        $normalized = str_replace('-', '_', trim($geminiName));
        foreach ($this->registry->all() as $tool) {
            if ($tool->name() === $normalized || $this->geminiName($tool->name()) === $normalized) {
                return $tool->name();
            }
        }

        return str_replace('_', '.', $normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function declaration(FinanceAssistantTool $tool): array
    {
        return [
            'type' => 'function',
            'name' => $this->geminiName($tool->name()),
            'description' => $tool->description(),
            'parameters' => $this->jsonSchema($tool->name()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(string $internalName): array
    {
        return match ($internalName) {
            'invoices.lookup', 'invoices.details' => [
                'type' => 'object',
                'properties' => [
                    'invoice_number' => [
                        'type' => 'string',
                        'description' => 'Invoice number or partial reference such as INV-1042.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Alias for invoice_number.',
                    ],
                ],
            ],
            'invoices.overdue' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 20,
                        'description' => 'Max overdue invoices to list.',
                    ],
                ],
            ],
            'invoices.by_customer', 'customers.invoices', 'customers.overdue' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name or partial name.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Alias for customer_name.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                ],
            ],
            'invoices.count' => [
                'type' => 'object',
                'properties' => [
                    'preset' => [
                        'type' => 'string',
                        'enum' => ['today', 'week', 'month', 'last_month'],
                    ],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                    'lifecycle' => [
                        'type' => 'string',
                        'enum' => ['overdue', 'paid', 'partial', 'sent', 'draft', 'cancelled'],
                    ],
                ],
            ],
            'customers.lookup', 'customers.balance' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name or partial name.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Alias for customer_name.',
                    ],
                ],
            ],
            'customers.statement' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => ['type' => 'string'],
                    'query' => ['type' => 'string'],
                    'preset' => [
                        'type' => 'string',
                        'enum' => ['today', 'week', 'month', 'last_month'],
                    ],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                ],
            ],
            'contracts.lookup', 'contracts.details', 'contracts.balance', 'contracts.invoices', 'contracts.unbilled', 'contracts.schedules' => [
                'type' => 'object',
                'properties' => [
                    'contract_number' => [
                        'type' => 'string',
                        'description' => 'Contract number such as CTR-000001.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Alias for contract_number.',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name when contract number is unknown.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                ],
            ],
            'billing_schedules.upcoming' => [
                'type' => 'object',
                'properties' => [
                    'days' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 90,
                        'description' => 'Horizon in days (default 30).',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                    'include_overdue' => [
                        'type' => 'boolean',
                        'description' => 'Include overdue schedules (default true).',
                    ],
                ],
            ],
            'compare.periods' => [
                'type' => 'object',
                'properties' => [
                    'preset' => [
                        'type' => 'string',
                        'enum' => ['month', 'week'],
                        'description' => 'Compare this month vs last month, or this week vs last week.',
                    ],
                ],
            ],
            'finance.alerts', 'suppliers.payable', 'inventory.value' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            default => [
                'type' => 'object',
                'properties' => [
                    'preset' => [
                        'type' => 'string',
                        'enum' => ['today', 'week', 'month', 'last_month'],
                        'description' => 'Named period. Prefer this over free-form dates when possible.',
                    ],
                    'from' => [
                        'type' => 'string',
                        'description' => 'Inclusive start date YYYY-MM-DD.',
                    ],
                    'to' => [
                        'type' => 'string',
                        'description' => 'Inclusive end date YYYY-MM-DD.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'description' => 'Max rows for ranked lists.',
                    ],
                ],
            ],
        };
    }
}
