<?php

namespace App\Services\Finance\Assistant\Conversation;

final class ConversationFollowUpResolver
{
    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>|null
     */
    public function resolve(string $question, ConversationEntityState $state): ?array
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return null;
        }

        if ($this->isCompareFollowUp($q)) {
            return $this->compareFollowUp($state);
        }

        if ($this->isInvoicePaidFollowUp($q) && $state->invoiceNumber !== null) {
            return [[
                'name' => 'invoices.details',
                'arguments' => ['invoice_number' => $state->invoiceNumber],
            ]];
        }

        if ($this->isCustomerInvoicesFollowUp($q) && $state->customerName !== null) {
            return [[
                'name' => 'invoices.by_customer',
                'arguments' => ['customer_name' => $state->customerName],
            ]];
        }

        if ($this->isContractBalanceFollowUp($q) && $state->contractNumber !== null) {
            return [[
                'name' => 'contracts.balance',
                'arguments' => ['contract_number' => $state->contractNumber],
            ]];
        }

        if ($this->isContractLookupFollowUp($q) && $state->customerName !== null) {
            return [[
                'name' => 'contracts.lookup',
                'arguments' => ['customer_name' => $state->customerName],
            ]];
        }

        if ($this->isOutstandingFollowUp($q)) {
            if ($state->entityFocus === ConversationEntityState::FOCUS_CONTRACT && $state->contractNumber !== null) {
                return [[
                    'name' => 'contracts.balance',
                    'arguments' => ['contract_number' => $state->contractNumber],
                ]];
            }

            if ($state->customerName !== null) {
                return [[
                    'name' => 'customers.balance',
                    'arguments' => ['customer_name' => $state->customerName],
                ]];
            }
        }

        if ($name = $this->bareName($question)) {
            return $this->bareNameFollowUp($name, $state);
        }

        return null;
    }

    /**
     * @param  list<array{name: string, arguments?: array<string, mixed>}>  $plans
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function enrichPlans(array $plans, ConversationEntityState $state): array
    {
        return array_map(function (array $plan) use ($state): array {
            $name = (string) ($plan['name'] ?? '');
            $arguments = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];

            if ($this->needsCustomerName($name) && ! $this->hasCustomerArg($arguments) && $state->customerName !== null) {
                $arguments['customer_name'] = $state->customerName;
            }

            if ($this->needsInvoiceNumber($name) && ! $this->hasInvoiceArg($arguments) && $state->invoiceNumber !== null) {
                $arguments['invoice_number'] = $state->invoiceNumber;
            }

            if ($this->needsContractNumber($name) && ! $this->hasContractArg($arguments) && $state->contractNumber !== null) {
                $arguments['contract_number'] = $state->contractNumber;
            }

            if ($this->needsPeriod($name) && $arguments === [] && $state->periodPreset !== null) {
                $arguments['preset'] = $state->periodPreset;
            }

            $plan['arguments'] = $arguments;

            return $plan;
        }, $plans);
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function compareFollowUp(ConversationEntityState $state): array
    {
        $preset = $state->periodPreset ?? 'month';
        if ($state->periodPreset === null && $state->periodFrom !== null && $state->periodTo !== null) {
            return [[
                'name' => 'compare.periods',
                'arguments' => [
                    'from' => $state->periodFrom,
                    'to' => $state->periodTo,
                ],
            ]];
        }

        return [[
            'name' => 'compare.periods',
            'arguments' => ['preset' => $preset],
        ]];
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>|null
     */
    private function bareNameFollowUp(string $name, ConversationEntityState $state): ?array
    {
        if ($state->entityFocus === ConversationEntityState::FOCUS_CUSTOMER || $this->lastToolsInclude($state, 'customers.')) {
            return [[
                'name' => 'customers.balance',
                'arguments' => ['customer_name' => $name],
            ]];
        }

        if ($state->entityFocus === ConversationEntityState::FOCUS_CONTRACT || $this->lastToolsInclude($state, 'contracts.')) {
            return [[
                'name' => 'contracts.lookup',
                'arguments' => ['customer_name' => $name],
            ]];
        }

        if ($state->customerName !== null) {
            return [[
                'name' => 'customers.balance',
                'arguments' => ['customer_name' => $name],
            ]];
        }

        return null;
    }

    private function isCompareFollowUp(string $q): bool
    {
        if (preg_match('/^(?:و?قارن(?:ها|هم| مع)?|compare(?: it| them)?)\??$/u', $q) === 1) {
            return true;
        }

        return $this->containsAny($q, ['قارنها', 'قارنهم', 'compare it'])
            && ! $this->containsAny($q, ['أسبوع', 'week', 'شهر', 'month', 'اليوم', 'today']);
    }

    private function isInvoicePaidFollowUp(string $q): bool
    {
        return $this->containsAny($q, [
            'اندفعت', 'إندفعت', 'دفعت', 'paid', 'هل اندفع', 'هل دفع',
        ]);
    }

    private function isCustomerInvoicesFollowUp(string $q): bool
    {
        return $this->containsAny($q, ['فواتير', 'invoices', 'فاتورة'])
            && $this->containsAny($q, ['عليه', 'عنده', 'له', 'his', 'her']);
    }

    private function isContractBalanceFollowUp(string $q): bool
    {
        return $this->containsAny($q, ['فوترنا', 'فوتر', 'billed', 'unbilled'])
            && $this->containsAny($q, ['منه', 'منها', 'from it', 'from him']);
    }

    private function isContractLookupFollowUp(string $q): bool
    {
        return $this->containsAny($q, ['عنده عقد', 'عندها عقد', 'has contract', 'عقود']);
    }

    private function isOutstandingFollowUp(string $q): bool
    {
        return $this->containsAny($q, ['كم باقي', 'باقي', 'remaining', 'outstanding', 'مستحق'])
            && ! $this->containsAny($q, ['عقد', 'contract']);
    }

    private function bareName(string $question): ?string
    {
        $trimmed = trim($question);
        if (preg_match('/^(?:طيب\s+)?([\p{L}][\p{L}\s]{1,38}[\p{L}])\??$/u', $trimmed, $matches) !== 1) {
            return null;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $matches[1]) ?? $matches[1]);

        return $name !== '' ? $name : null;
    }

    private function lastToolsInclude(ConversationEntityState $state, string $prefix): bool
    {
        foreach ($state->lastTools as $tool) {
            if (str_starts_with($tool, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function needsCustomerName(string $tool): bool
    {
        return str_starts_with($tool, 'customers.')
            || in_array($tool, ['invoices.by_customer', 'contracts.lookup', 'contracts.balance', 'contracts.invoices', 'contracts.unbilled', 'contracts.schedules'], true);
    }

    private function needsInvoiceNumber(string $tool): bool
    {
        return str_starts_with($tool, 'invoices.');
    }

    private function needsContractNumber(string $tool): bool
    {
        return str_starts_with($tool, 'contracts.') || $tool === 'billing_schedules.upcoming';
    }

    private function needsPeriod(string $tool): bool
    {
        return in_array($tool, [
            'sales.period',
            'expenses.period',
            'profit.net',
            'profit.gross',
            'profit.cogs',
            'finance.summary',
            'invoices.summary',
            'invoices.count',
            'customers.top',
            'products.top',
            'payroll.period',
            'expenses.by_category',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function hasCustomerArg(array $arguments): bool
    {
        return trim((string) ($arguments['customer_name'] ?? $arguments['query'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function hasInvoiceArg(array $arguments): bool
    {
        return trim((string) ($arguments['invoice_number'] ?? $arguments['query'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function hasContractArg(array $arguments): bool
    {
        return trim((string) ($arguments['contract_number'] ?? $arguments['query'] ?? '')) !== ''
            || trim((string) ($arguments['customer_name'] ?? '')) !== '';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
