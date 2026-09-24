<?php

namespace App\Services\Finance\Assistant\Conversation;

use App\Models\Finance\FinanceAiConversation;

final class ConversationEntityTracker
{
    public function __construct(
        private readonly ConversationFollowUpResolver $followUpResolver,
    ) {}

    public function fromConversation(FinanceAiConversation $conversation): ConversationEntityState
    {
        $state = ConversationEntityState::fromArray(
            is_array($conversation->context_entities) ? $conversation->context_entities : null,
        );

        if ($state->lastMetrics === [] && is_array($conversation->last_metric_names)) {
            $state = $state->with(['lastMetrics' => array_values($conversation->last_metric_names)]);
        }

        if ($state->periodFrom === null && $conversation->last_period_from !== null) {
            $state = $state->with(['periodFrom' => $conversation->last_period_from->toDateString()]);
        }

        if ($state->periodTo === null && $conversation->last_period_to !== null) {
            $state = $state->with(['periodTo' => $conversation->last_period_to->toDateString()]);
        }

        return $state;
    }

    public function mergeFromQuestion(ConversationEntityState $state, string $question): ConversationEntityState
    {
        $overrides = [];

        if ($invoiceNumber = $this->extractInvoiceNumber($question)) {
            $overrides['invoiceNumber'] = $invoiceNumber;
            $overrides['entityFocus'] = ConversationEntityState::FOCUS_INVOICE;
        }

        if ($contractNumber = $this->extractContractNumber($question)) {
            $overrides['contractNumber'] = $contractNumber;
            $overrides['entityFocus'] = ConversationEntityState::FOCUS_CONTRACT;
        }

        if ($customerName = $this->extractCustomerName($question)) {
            $overrides['customerName'] = $customerName;
            if (($overrides['entityFocus'] ?? $state->entityFocus) === null) {
                $overrides['entityFocus'] = ConversationEntityState::FOCUS_CUSTOMER;
            }
        }

        if ($periodPreset = $this->extractPeriodPreset($question)) {
            $overrides['periodPreset'] = $periodPreset;
        }

        return $overrides === [] ? $state : $state->with($overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mergeFromTurn(ConversationEntityState $state, array $payload): ConversationEntityState
    {
        $facts = is_array($payload['facts'] ?? null) ? $payload['facts'] : [];
        $tools = is_array($payload['tools_used'] ?? null) ? $payload['tools_used'] : [];
        $period = is_array($payload['period'] ?? null) ? $payload['period'] : null;

        $overrides = [
            'lastTools' => array_values(array_filter(array_map(
                fn (mixed $tool): string => trim((string) $tool),
                $tools,
            ))),
        ];

        if ($period !== null) {
            if (isset($period['from'])) {
                $overrides['periodFrom'] = (string) $period['from'];
            }
            if (isset($period['to'])) {
                $overrides['periodTo'] = (string) $period['to'];
            }
        }

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $data = is_array($fact['data'] ?? null) ? $fact['data'] : [];
            $tool = (string) ($fact['tool'] ?? '');

            if (isset($data['customer']['name']) && is_string($data['customer']['name'])) {
                $overrides['customerName'] = trim($data['customer']['name']);
                $overrides['entityFocus'] = ConversationEntityState::FOCUS_CUSTOMER;
            }

            if (isset($data['invoice']['invoice_number']) && is_string($data['invoice']['invoice_number'])) {
                $overrides['invoiceNumber'] = trim($data['invoice']['invoice_number']);
                $overrides['entityFocus'] = ConversationEntityState::FOCUS_INVOICE;
            }

            if (isset($data['contract']['contract_number']) && is_string($data['contract']['contract_number'])) {
                $overrides['contractNumber'] = trim($data['contract']['contract_number']);
                $overrides['entityFocus'] = ConversationEntityState::FOCUS_CONTRACT;
            } elseif (str_starts_with($tool, 'invoices.') && isset($data['query']) && is_string($data['query'])) {
                $overrides['invoiceNumber'] = trim($data['query']);
                $overrides['entityFocus'] = ConversationEntityState::FOCUS_INVOICE;
            }

            if (isset($data['period']['preset']) && is_string($data['period']['preset'])) {
                $overrides['periodPreset'] = trim($data['period']['preset']);
            }

            if (($overrides['entityFocus'] ?? $state->entityFocus) === null && $this->isMetricTool($tool)) {
                $overrides['entityFocus'] = ConversationEntityState::FOCUS_METRIC;
            }
        }

        $metricNames = array_values(array_unique(array_filter(array_map(
            fn (array $fact): string => (string) ($fact['tool'] ?? ''),
            array_filter($facts, fn (mixed $fact): bool => is_array($fact)),
        ))));

        if ($metricNames !== []) {
            $overrides['lastMetrics'] = $metricNames;
        }

        return $state->with($overrides);
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>|null
     */
    public function resolveFollowUpPlans(string $question, ConversationEntityState $state): ?array
    {
        return $this->followUpResolver->resolve($question, $state);
    }

    /**
     * @param  list<array{name: string, arguments?: array<string, mixed>}>  $plans
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function enrichPlans(array $plans, ConversationEntityState $state): array
    {
        return $this->followUpResolver->enrichPlans($plans, $state);
    }

    private function extractInvoiceNumber(string $question): ?string
    {
        if (preg_match('/(?:inv[- ]?)(\d+)/i', $question, $matches) === 1) {
            return 'INV-'.$matches[1];
        }

        if (preg_match('/(?:inv[- ]?\d+)/i', $question, $matches) === 1) {
            return strtoupper(str_replace(' ', '', $matches[0]));
        }

        return null;
    }

    private function extractContractNumber(string $question): ?string
    {
        if (preg_match('/(?:ctr[- ]?)(\d+)/i', $question, $matches) === 1) {
            return 'CTR-'.$matches[1];
        }

        if (preg_match('/(?:ctr[- ]?\d+)/i', $question, $matches) === 1) {
            return strtoupper(str_replace(' ', '', $matches[0]));
        }

        return null;
    }

    private function extractCustomerName(string $question): ?string
    {
        $patterns = [
            '/(?:فواتير|فاتورة|invoices?)\s+(?:ل|لـ|ل\s)?([\p{L}\s]{2,40})/u',
            '/(?:عميل|customer)\s+(?:اسمه\s)?([\p{L}\s]{2,40})/u',
            '/(?:رصيد|balance)\s+عميل\s+([\p{L}\s]{2,40})/u',
            '/(?:كم\s+)?(?:عليه|عنده|مستحق)\s+([\p{L}\s]{2,40})/u',
            '/(?:عقد\s+(?:مع|ل)\s+)([\p{L}\s]{2,40})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $question, $matches) === 1) {
                $name = trim(preg_replace('/\s+/u', ' ', $matches[1]) ?? $matches[1]);
                $name = preg_replace('/\s*(?:عليه|مستحق|فواتير|فاتورة).*$/u', '', $name) ?? $name;
                $name = trim($name);

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    private function extractPeriodPreset(string $question): ?string
    {
        $q = mb_strtolower(trim($question));

        if (str_contains($q, 'اليوم') || str_contains($q, 'today')) {
            return 'today';
        }
        if (str_contains($q, 'الأسبوع') || str_contains($q, 'week')) {
            return 'week';
        }
        if (str_contains($q, 'الشهر الماضي') || str_contains($q, 'last month')) {
            return 'last_month';
        }
        if (str_contains($q, 'الشهر') || str_contains($q, 'month') || str_contains($q, 'هالشهر')) {
            return 'month';
        }

        return null;
    }

    private function isMetricTool(string $tool): bool
    {
        return in_array($tool, [
            'sales.period',
            'sales.today',
            'expenses.period',
            'profit.net',
            'profit.gross',
            'profit.cogs',
            'finance.summary',
            'compare.periods',
            'customers.top',
            'products.top',
            'payroll.period',
            'expenses.by_category',
            'suppliers.payable',
            'inventory.value',
            'finance.alerts',
            'invoices.summary',
            'invoices.count',
        ], true);
    }
}
