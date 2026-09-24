<?php

namespace App\Services\Finance\Assistant\Concerns;

use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceInvoice;
use App\Models\Workspace;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

trait ResolvesAssistantEntities
{
    protected function normalizeLookupQuery(string $query): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);

        return $query;
    }

    /**
     * @return list<string>
     */
    protected function invoiceNumberPatterns(string $query): array
    {
        $term = $this->normalizeLookupQuery($query);
        $patterns = array_filter([$term]);
        $compact = strtoupper(str_replace(' ', '', $term));

        if ($compact !== '') {
            $patterns[] = $compact;
        }

        if (preg_match('/(\d+)/', $term, $matches) === 1) {
            $digits = $matches[1];
            $patterns[] = $digits;
            $patterns[] = 'INV-'.$digits;
            $patterns[] = 'INV'.$digits;
        }

        return array_values(array_unique(array_filter($patterns)));
    }

    /**
     * @return Collection<int, FinanceInvoice>
     */
    protected function findInvoicesByNumber(Workspace $workspace, string $query, int $limit = 6): Collection
    {
        $patterns = $this->invoiceNumberPatterns($query);
        if ($patterns === []) {
            return collect();
        }

        return FinanceInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'sales')
            ->where(function ($builder) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $builder->orWhere('invoice_number', 'like', '%'.$pattern.'%');
                }
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(max(1, min(10, $limit)))
            ->get();
    }

    /**
     * @return array{
     *     status: 'found'|'not_found'|'ambiguous',
     *     invoice: ?FinanceInvoice,
     *     candidates: list<array<string, mixed>>
     * }
     */
    protected function resolveSingleInvoice(Workspace $workspace, string $query): array
    {
        $matches = $this->findInvoicesByNumber($workspace, $query, 6);
        if ($matches->isEmpty()) {
            return [
                'status' => 'not_found',
                'invoice' => null,
                'candidates' => [],
            ];
        }

        $compactQuery = strtoupper(str_replace(' ', '', $this->normalizeLookupQuery($query)));
        $exact = $matches->first(function (FinanceInvoice $invoice) use ($compactQuery): bool {
            $number = strtoupper(str_replace(' ', '', (string) $invoice->invoice_number));

            return $number === $compactQuery || str_contains($number, $compactQuery);
        });

        if ($matches->count() === 1) {
            return [
                'status' => 'found',
                'invoice' => $matches->first(),
                'candidates' => [],
            ];
        }

        if ($exact !== null) {
            return [
                'status' => 'found',
                'invoice' => $exact,
                'candidates' => [],
            ];
        }

        return [
            'status' => 'ambiguous',
            'invoice' => null,
            'candidates' => $matches->take(5)->map(fn (FinanceInvoice $invoice): array => $this->invoiceRow($invoice))->all(),
        ];
    }

    /**
     * @return Collection<int, Customer>
     */
    protected function findCustomersByQuery(Workspace $workspace, string $query, int $limit = 6): Collection
    {
        $term = $this->normalizeLookupQuery($query);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.$term.'%';

        return Customer::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('name')
            ->limit(max(1, min(10, $limit)))
            ->get();
    }

    /**
     * @return array{
     *     status: 'found'|'not_found'|'ambiguous',
     *     customer: ?Customer,
     *     candidates: list<array<string, mixed>>
     * }
     */
    protected function resolveSingleCustomer(Workspace $workspace, string $query): array
    {
        $matches = $this->findCustomersByQuery($workspace, $query, 6);
        if ($matches->isEmpty()) {
            return [
                'status' => 'not_found',
                'customer' => null,
                'candidates' => [],
            ];
        }

        $normalized = mb_strtolower($this->normalizeLookupQuery($query));
        $exact = $matches->first(fn (Customer $customer): bool => mb_strtolower((string) $customer->name) === $normalized);

        if ($matches->count() === 1) {
            return [
                'status' => 'found',
                'customer' => $matches->first(),
                'candidates' => [],
            ];
        }

        if ($exact !== null) {
            return [
                'status' => 'found',
                'customer' => $exact,
                'candidates' => [],
            ];
        }

        return [
            'status' => 'ambiguous',
            'customer' => null,
            'candidates' => $matches->take(5)->map(fn (Customer $customer): array => $this->customerRow($customer))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoiceRow(FinanceInvoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'customer_name' => $invoice->customer?->name ?? $invoice->customer_name,
            'issue_date' => optional($invoice->issue_date)?->toDateString(),
            'due_date' => optional($invoice->due_date)?->toDateString(),
            'total' => Money::of($invoice->total),
            'amount_paid' => Money::of($invoice->amount_paid),
            'amount_due' => Money::of($invoice->amount_due),
            'document_status' => $invoice->invoice_status ?: $invoice->status,
            'payment_status' => $invoice->payment_status,
            'currency' => $invoice->currency ?: 'SAR',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoiceDetailRow(FinanceInvoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'payments.creator', 'payments.receipt', 'payments.treasuryAccount']);

        $row = $this->invoiceRow($invoice);
        $row['notes'] = $invoice->notes;
        $row['issued_at'] = optional($invoice->issued_at)?->toIso8601String();
        $row['payments'] = $invoice->payments?->map(fn ($payment): array => [
            'payment_date' => optional($payment->payment_date)?->toDateString(),
            'amount' => Money::of($payment->amount),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'status' => $payment->status,
            'recorded_by' => $payment->creator?->name,
        ])->values()->all() ?? [];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerRow(Customer $customer, ?float $outstanding = null): array
    {
        return array_filter([
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'outstanding' => $outstanding !== null ? Money::of($outstanding) : null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    protected function contractNumberPatterns(string $query): array
    {
        $term = $this->normalizeLookupQuery($query);
        $patterns = array_filter([$term]);
        $compact = strtoupper(str_replace(' ', '', $term));

        if ($compact !== '') {
            $patterns[] = $compact;
        }

        if (preg_match('/(\d+)/', $term, $matches) === 1) {
            $digits = $matches[1];
            $patterns[] = $digits;
            $patterns[] = 'CTR-'.str_pad($digits, 6, '0', STR_PAD_LEFT);
            $patterns[] = 'CTR-'.$digits;
            $patterns[] = 'CTR'.$digits;
        }

        return array_values(array_unique(array_filter($patterns)));
    }

    /**
     * @return Collection<int, Contract>
     */
    protected function findContractsByNumber(Workspace $workspace, string $query, int $limit = 6): Collection
    {
        $patterns = $this->contractNumberPatterns($query);
        if ($patterns === []) {
            return collect();
        }

        return Contract::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($builder) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $builder->orWhere('contract_number', 'like', '%'.$pattern.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(max(1, min(10, $limit)))
            ->get();
    }

    /**
     * @return Collection<int, Contract>
     */
    protected function findContractsByCustomerName(Workspace $workspace, string $customerName, int $limit = 6): Collection
    {
        $term = $this->normalizeLookupQuery($customerName);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.$term.'%';

        return Contract::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('customer', fn ($query) => $query->where('name', 'like', $like))
            ->with('customer')
            ->orderByDesc('id')
            ->limit(max(1, min(10, $limit)))
            ->get();
    }

    /**
     * @return array{
     *     status: 'found'|'not_found'|'ambiguous',
     *     contract: ?Contract,
     *     candidates: list<array<string, mixed>>
     * }
     */
    protected function resolveSingleContract(Workspace $workspace, ?string $contractQuery, ?string $customerName = null): array
    {
        $matches = collect();

        if ($contractQuery !== null && trim($contractQuery) !== '') {
            $matches = $this->findContractsByNumber($workspace, $contractQuery, 6);
        }

        if ($matches->isEmpty() && $customerName !== null && trim($customerName) !== '') {
            $matches = $this->findContractsByCustomerName($workspace, $customerName, 6);
        }

        if ($matches->isEmpty()) {
            return [
                'status' => 'not_found',
                'contract' => null,
                'candidates' => [],
            ];
        }

        if ($matches->count() === 1) {
            return [
                'status' => 'found',
                'contract' => $matches->first(),
                'candidates' => [],
            ];
        }

        if ($contractQuery !== null && trim($contractQuery) !== '') {
            $compactQuery = strtoupper(str_replace(' ', '', $this->normalizeLookupQuery($contractQuery)));
            $exact = $matches->first(function (Contract $contract) use ($compactQuery): bool {
                $number = strtoupper(str_replace(' ', '', (string) $contract->contract_number));

                return $number === $compactQuery || str_contains($number, $compactQuery);
            });

            if ($exact !== null) {
                return [
                    'status' => 'found',
                    'contract' => $exact,
                    'candidates' => [],
                ];
            }
        }

        return [
            'status' => 'ambiguous',
            'contract' => null,
            'candidates' => $matches->take(5)->map(fn (Contract $contract): array => $this->contractRow($contract))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contractRow(Contract $contract): array
    {
        $contract->loadMissing('customer');

        return [
            'contract_number' => $contract->contract_number,
            'title' => $contract->title,
            'status' => $contract->status,
            'customer_name' => $contract->customer?->name,
            'start_date' => optional($contract->start_date)?->toDateString(),
            'end_date' => optional($contract->end_date)?->toDateString(),
            'value' => Money::of($contract->value),
            'currency' => $contract->currency ?: 'SAR',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contractDetailRow(Contract $contract): array
    {
        $contract->loadMissing(['customer', 'items', 'billingSchedules']);

        $row = $this->contractRow($contract);
        $row['description'] = $contract->description;
        $row['payment_terms'] = $contract->payment_terms;
        $row['signature_status'] = $contract->signature_status;
        $row['items_count'] = $contract->items?->count() ?? 0;
        $row['schedules_count'] = $contract->billingSchedules?->count() ?? 0;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function billingScheduleRow(FinanceBillingSchedule $schedule): array
    {
        $schedule->loadMissing(['contract', 'customer']);

        return [
            'title' => $schedule->title,
            'contract_number' => $schedule->contract?->contract_number,
            'customer_name' => $schedule->customer?->name ?? $schedule->contract?->customer?->name,
            'status' => $schedule->status,
            'frequency' => $schedule->frequency,
            'amount' => Money::of($schedule->amount),
            'currency' => $schedule->currency ?: 'SAR',
            'next_run_on' => optional($schedule->next_run_on)?->toDateString(),
            'start_date' => optional($schedule->start_date)?->toDateString(),
            'end_date' => optional($schedule->end_date)?->toDateString(),
            'generated_count' => (int) $schedule->generated_count,
            'total_occurrences' => $schedule->total_occurrences,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    protected function contractBalancePayload(array $summary): array
    {
        return [
            'contract_value' => Money::of($summary['contract_value'] ?? 0),
            'invoiced' => Money::of($summary['invoiced'] ?? 0),
            'paid' => Money::of($summary['paid'] ?? 0),
            'outstanding' => Money::of($summary['outstanding'] ?? 0),
            'unbilled' => Money::of($summary['unbilled'] ?? 0),
            'overdue' => Money::of($summary['overdue'] ?? 0),
        ];
    }
}
