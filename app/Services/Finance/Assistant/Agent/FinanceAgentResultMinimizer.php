<?php

namespace App\Services\Finance\Assistant\Agent;

use App\Services\Finance\Assistant\FinanceAssistantToolResult;

final class FinanceAgentResultMinimizer
{
    /**
     * @return array<string, mixed>
     */
    public function minimize(FinanceAssistantToolResult $result): array
    {
        $data = $this->stripSensitive($result->data);

        if (! $result->available) {
            $payload = [
                'available' => false,
                'reason' => $result->reason,
            ];
            if (isset($data['requested_period']) && is_array($data['requested_period'])) {
                $payload['requested_period'] = [
                    'from' => $data['requested_period']['from'] ?? null,
                    'to' => $data['requested_period']['to'] ?? null,
                ];
            }

            return $payload;
        }

        $period = is_array($data['period'] ?? null) ? [
            'from' => $data['period']['from'] ?? null,
            'to' => $data['period']['to'] ?? null,
        ] : null;

        $base = [
            'available' => true,
            'metric' => $result->tool,
            'currency' => $data['currency'] ?? null,
            'period' => $period,
            'source' => $data['source'] ?? null,
        ];

        return match ($result->tool) {
            'customers.top', 'products.top', 'expenses.by_category' => $base + [
                'items' => $this->listItems($data['items'] ?? []),
            ],
            'compare.periods' => $base + [
                'current' => $this->periodSlice($data['current'] ?? []),
                'previous' => $this->periodSlice($data['previous'] ?? []),
                'delta' => $this->moneyMap($data['delta'] ?? []),
            ],
            'finance.summary' => $base + $this->moneyMap([
                'revenue' => $data['revenue'] ?? null,
                'expenses' => $data['expenses'] ?? null,
                'gross_profit' => $data['gross_profit'] ?? null,
                'net_profit' => $data['net_profit'] ?? null,
                'cogs' => $data['cogs'] ?? null,
                'payables' => $data['payables'] ?? null,
                'receivables' => $data['receivables'] ?? null,
                'payroll_paid_total' => $data['payroll_paid_total'] ?? null,
            ]),
            'finance.alerts' => $base + [
                'count' => (int) ($data['count'] ?? 0),
                'items' => $this->alertItems($data['items'] ?? []),
            ],
            'invoices.lookup', 'invoices.details' => $base + array_filter([
                'status' => $data['status'] ?? null,
                'query' => $data['query'] ?? null,
                'invoice' => is_array($data['invoice'] ?? null) ? $this->invoiceSlice($data['invoice']) : null,
                'candidates' => $this->invoiceCandidates($data['candidates'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'invoices.overdue', 'customers.overdue' => $base + array_filter([
                'count' => $data['count'] ?? null,
                'total_due' => $this->scalar($data['total_due'] ?? null),
                'customer' => is_array($data['customer'] ?? null) ? $this->customerSlice($data['customer']) : null,
                'invoices' => $this->invoiceCandidates($data['invoices'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'invoices.by_customer', 'customers.invoices' => $base + array_filter([
                'status' => $data['status'] ?? null,
                'customer' => is_array($data['customer'] ?? null) ? $this->customerSlice($data['customer']) : null,
                'count' => $data['count'] ?? null,
                'invoices' => $this->invoiceCandidates($data['invoices'] ?? []),
                'candidates' => $this->customerCandidates($data['candidates'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'invoices.summary' => $base + array_filter([
                'count' => $data['count'] ?? null,
                'total' => $this->scalar($data['total'] ?? null),
                'amount_due' => $this->scalar($data['amount_due'] ?? null),
                'overdue' => $this->scalar($data['overdue'] ?? null),
            ], fn ($value): bool => $value !== null),
            'invoices.count' => $base + array_filter([
                'count' => $data['count'] ?? null,
                'lifecycle' => $data['lifecycle'] ?? null,
            ], fn ($value): bool => $value !== null),
            'customers.lookup', 'customers.balance' => $base + array_filter([
                'status' => $data['status'] ?? null,
                'query' => $data['query'] ?? null,
                'value' => $this->scalar($data['value'] ?? null),
                'customer' => is_array($data['customer'] ?? null) ? $this->customerSlice($data['customer']) : null,
                'candidates' => $this->customerCandidates($data['candidates'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'contracts.lookup', 'contracts.details' => $base + array_filter([
                'status' => $data['status'] ?? null,
                'contract' => is_array($data['contract'] ?? null) ? $this->contractSlice($data['contract']) : null,
                'balance' => is_array($data['balance'] ?? null) ? $this->contractBalanceSlice($data['balance']) : null,
                'candidates' => $this->contractCandidates($data['candidates'] ?? []),
                'invoices_count' => $data['invoices_count'] ?? null,
                'payments_count' => $data['payments_count'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== []),
            'contracts.balance', 'contracts.unbilled' => $base + array_filter([
                'status' => $data['status'] ?? null,
                'contract' => is_array($data['contract'] ?? null) ? $this->contractSlice($data['contract']) : null,
                'contract_value' => $this->scalar($data['contract_value'] ?? null),
                'invoiced' => $this->scalar($data['invoiced'] ?? null),
                'paid' => $this->scalar($data['paid'] ?? null),
                'outstanding' => $this->scalar($data['outstanding'] ?? null),
                'unbilled' => $this->scalar($data['unbilled'] ?? null),
                'overdue' => $this->scalar($data['overdue'] ?? null),
                'value' => $this->scalar($data['value'] ?? null),
                'candidates' => $this->contractCandidates($data['candidates'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'contracts.invoices' => $base + array_filter([
                'status' => $data['status'] ?? null,
                'contract' => is_array($data['contract'] ?? null) ? $this->contractSlice($data['contract']) : null,
                'count' => $data['count'] ?? null,
                'invoices' => $this->invoiceCandidates($data['invoices'] ?? []),
                'candidates' => $this->contractCandidates($data['candidates'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'contracts.schedules', 'billing_schedules.upcoming' => $base + array_filter([
                'count' => $data['count'] ?? null,
                'contract' => is_array($data['contract'] ?? null) ? $this->contractSlice($data['contract']) : null,
                'schedules' => $this->scheduleCandidates($data['schedules'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'customers.statement' => $base + array_filter([
                'customer' => is_array($data['customer'] ?? null) ? $this->customerSlice($data['customer']) : null,
                'opening_balance' => $this->scalar($data['opening_balance'] ?? null),
                'closing_balance' => $this->scalar($data['closing_balance'] ?? null),
                'invoices_total' => $this->scalar($data['invoices_total'] ?? null),
                'payments_total' => $this->scalar($data['payments_total'] ?? null),
                'lines' => $this->statementLines($data['lines'] ?? []),
            ], fn ($value): bool => $value !== null && $value !== []),
            'suppliers.payable' => $base + [
                'value' => $this->scalar($data['value'] ?? null),
                'total_due' => $this->scalar($data['total_due'] ?? null),
                'aging' => $this->aging($data['aging'] ?? []),
            ],
            'payroll.employees_count' => $base + [
                'count' => (int) ($data['count'] ?? 0),
                'status' => $data['status'] ?? 'active',
                'label_ar' => $data['label_ar'] ?? null,
                'label_en' => $data['label_en'] ?? null,
            ],
            default => $base + array_filter([
                'value' => $this->scalar($data['value'] ?? $data['net_profit'] ?? null),
                'metric_kind' => $data['metric_kind'] ?? null,
                'label_ar' => $data['label_ar'] ?? null,
                'label_en' => $data['label_en'] ?? null,
                'expenses_count' => $data['expenses_count'] ?? null,
                'records_count' => $data['records_count'] ?? null,
                'products_count' => $data['products_count'] ?? null,
            ], fn ($value): bool => $value !== null),
        };
    }

    /**
     * @return list<array{name: string, value: mixed}>
     */
    private function listItems(mixed $items): array
    {
        $rows = [];
        foreach ($this->iterable($items) as $item) {
            $row = is_object($item) ? (array) $item : (is_array($item) ? $item : []);
            $name = (string) ($row['name'] ?? $row['category_name'] ?? $row['customer_name'] ?? '');
            $value = $row['value'] ?? $row['total'] ?? $row['revenue'] ?? null;
            if ($name === '' && $value === null) {
                continue;
            }
            $rows[] = [
                'name' => $name !== '' ? $name : '—',
                'value' => $this->scalar($value),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alertItems(mixed $items): array
    {
        $rows = [];
        foreach ($this->iterable($items) as $item) {
            $row = is_object($item) ? (array) $item : (is_array($item) ? $item : []);
            $rows[] = [
                'title' => $row['title'] ?? null,
                'severity' => $row['severity'] ?? null,
                'count' => $row['count'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $slice
     * @return array<string, mixed>
     */
    private function periodSlice(array $slice): array
    {
        return [
            'label' => $slice['label'] ?? null,
            'from' => $slice['from'] ?? null,
            'to' => $slice['to'] ?? null,
            'revenue' => $this->scalar($slice['revenue'] ?? null),
            'expenses' => $this->scalar($slice['expenses'] ?? null),
            'net_profit' => $this->scalar($slice['net_profit'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function moneyMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $this->scalar($value);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aging(mixed $aging): array
    {
        $rows = [];
        foreach ($this->iterable($aging) as $bucket) {
            $row = is_object($bucket) ? (array) $bucket : (is_array($bucket) ? $bucket : []);
            $rows[] = [
                'label' => $row['label'] ?? $row['bucket'] ?? $row['name'] ?? null,
                'value' => $this->scalar($row['value'] ?? $row['total'] ?? $row['amount'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function invoiceSlice(array $invoice): array
    {
        return array_filter([
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'customer_name' => $invoice['customer_name'] ?? null,
            'issue_date' => $invoice['issue_date'] ?? null,
            'due_date' => $invoice['due_date'] ?? null,
            'total' => $this->scalar($invoice['total'] ?? null),
            'amount_paid' => $this->scalar($invoice['amount_paid'] ?? null),
            'amount_due' => $this->scalar($invoice['amount_due'] ?? null),
            'payment_status' => $invoice['payment_status'] ?? null,
            'document_status' => $invoice['document_status'] ?? null,
            'payments' => $this->paymentRows($invoice['payments'] ?? []),
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceCandidates(mixed $rows): array
    {
        $out = [];
        foreach ($this->iterable($rows) as $row) {
            $item = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $slice = $this->invoiceSlice($item);
            if ($slice !== []) {
                $out[] = $slice;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    private function customerSlice(array $customer): array
    {
        return array_filter([
            'name' => $customer['name'] ?? null,
            'email' => $customer['email'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'outstanding' => $this->scalar($customer['outstanding'] ?? null),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customerCandidates(mixed $rows): array
    {
        $out = [];
        foreach ($this->iterable($rows) as $row) {
            $item = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $slice = $this->customerSlice($item);
            if ($slice !== []) {
                $out[] = $slice;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentRows(mixed $rows): array
    {
        $out = [];
        foreach ($this->iterable($rows) as $row) {
            $item = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $out[] = array_filter([
                'payment_date' => $item['payment_date'] ?? null,
                'amount' => $this->scalar($item['amount'] ?? null),
                'method' => $item['method'] ?? null,
                'reference' => $item['reference'] ?? null,
                'status' => $item['status'] ?? null,
                'recorded_by' => $item['recorded_by'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== '');
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statementLines(mixed $rows): array
    {
        $out = [];
        foreach ($this->iterable($rows) as $row) {
            $item = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $out[] = array_filter([
                'date' => $item['date'] ?? null,
                'kind' => $item['kind'] ?? null,
                'reference' => $item['reference'] ?? null,
                'debit' => $this->scalar($item['debit'] ?? null),
                'credit' => $this->scalar($item['credit'] ?? null),
                'balance' => $this->scalar($item['balance'] ?? null),
            ], fn ($value): bool => $value !== null);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function contractSlice(array $contract): array
    {
        return array_filter([
            'contract_number' => $contract['contract_number'] ?? null,
            'title' => $contract['title'] ?? null,
            'status' => $contract['status'] ?? null,
            'customer_name' => $contract['customer_name'] ?? null,
            'start_date' => $contract['start_date'] ?? null,
            'end_date' => $contract['end_date'] ?? null,
            'value' => $this->scalar($contract['value'] ?? null),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contractCandidates(mixed $rows): array
    {
        $out = [];
        foreach ($this->iterable($rows) as $row) {
            $item = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $slice = $this->contractSlice($item);
            if ($slice !== []) {
                $out[] = $slice;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed>
     */
    private function contractBalanceSlice(array $balance): array
    {
        return array_filter([
            'contract_value' => $this->scalar($balance['contract_value'] ?? null),
            'invoiced' => $this->scalar($balance['invoiced'] ?? null),
            'paid' => $this->scalar($balance['paid'] ?? null),
            'outstanding' => $this->scalar($balance['outstanding'] ?? null),
            'unbilled' => $this->scalar($balance['unbilled'] ?? null),
            'overdue' => $this->scalar($balance['overdue'] ?? null),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleCandidates(mixed $rows): array
    {
        $out = [];
        foreach ($this->iterable($rows) as $row) {
            $item = is_object($row) ? (array) $row : (is_array($row) ? $row : []);
            $out[] = array_filter([
                'title' => $item['title'] ?? null,
                'contract_number' => $item['contract_number'] ?? null,
                'customer_name' => $item['customer_name'] ?? null,
                'status' => $item['status'] ?? null,
                'schedule_status' => $item['schedule_status'] ?? null,
                'frequency' => $item['frequency'] ?? null,
                'amount' => $this->scalar($item['amount'] ?? null),
                'next_run_on' => $item['next_run_on'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== '');
        }

        return $out;
    }

    private function scalar(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function iterable(mixed $items): array
    {
        if (is_array($items)) {
            return array_values($items);
        }
        if (is_object($items) && method_exists($items, 'all')) {
            return array_values($items->all());
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripSensitive(array $data): array
    {
        $blocked = ['workspace_id', 'tenant_id', 'tenant', 'permission', 'permissions', 'role', 'user_id'];
        $clean = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if (in_array($name, $blocked, true) || str_ends_with($name, '_id')) {
                continue;
            }
            if (is_array($value)) {
                $value = $this->stripSensitive($value);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
