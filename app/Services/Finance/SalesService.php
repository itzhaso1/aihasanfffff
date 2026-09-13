<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SalesService
{
    /**
     * @param  array<string,mixed>  $filters
     */
    public function paginateInvoices(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->salesInvoicesQuery($filters)
            ->with(['customer', 'payments'])
            ->latest('issue_date')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,float|int>
     */
    public function summary(array $filters = []): array
    {
        $query = $this->salesInvoicesQuery($filters);

        $invoiceCount = (clone $query)->count();
        $totalSales = (float) (clone $query)->sum('total');
        $totalDue = (float) (clone $query)->sum('amount_due');
        $totalPaid = (float) (clone $query)->sum('amount_paid');
        $overdueCount = (clone $query)
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->count();
        $unpaidCount = (clone $query)
            ->whereIssued()
            ->where(function (Builder $builder): void {
                $builder->where(function (Builder $stateQuery): void {
                    $stateQuery->wherePaymentStatus('unpaid');
                })->orWhere(function (Builder $stateQuery): void {
                    $stateQuery->wherePaymentStatus('partial');
                })->orWhere(function (Builder $stateQuery): void {
                    $stateQuery->wherePaymentStatus('overdue');
                });
            })
            ->count();

        return [
            'invoice_count' => $invoiceCount,
            'total_sales' => round($totalSales, 2),
            'total_due' => round($totalDue, 2),
            'total_paid' => round($totalPaid, 2),
            'overdue_count' => $overdueCount,
            'unpaid_count' => $unpaidCount,
        ];
    }

    public function recentPayments(int $limit = 15): \Illuminate\Support\Collection
    {
        return FinanceInvoicePayment::query()
            ->whereHas('invoice', fn (Builder $query) => $query->where('type', 'sales'))
            ->with(['invoice', 'treasuryAccount', 'creator'])
            ->latest('payment_date')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function salesInvoicesQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $invoiceStatus = (string) ($filters['invoice_status'] ?? '');
        $paymentStatus = (string) ($filters['payment_status'] ?? '');
        $legacyStatus = (string) ($filters['status'] ?? '');
        $customerId = (int) ($filters['customer_id'] ?? 0);
        $from = $this->resolveDate($filters['from'] ?? null);
        $to = $this->resolveDate($filters['to'] ?? null);

        if ($legacyStatus !== '' && $invoiceStatus === '' && $paymentStatus === '') {
            if (in_array($legacyStatus, ['draft', 'cancelled'], true)) {
                $invoiceStatus = $legacyStatus;
            } elseif ($legacyStatus === 'sent') {
                $invoiceStatus = 'issued';
            } elseif (in_array($legacyStatus, ['unpaid', 'partial', 'paid', 'overdue'], true)) {
                $paymentStatus = $legacyStatus;
            }
        }

        return FinanceInvoice::query()
            ->where('type', 'sales')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $innerQuery) use ($search): void {
                    $innerQuery
                        ->where('invoice_number', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($invoiceStatus !== '', fn (Builder $query) => $query->whereInvoiceStatus($invoiceStatus))
            ->when($paymentStatus !== '', fn (Builder $query) => $query->wherePaymentStatus($paymentStatus))
            ->when($customerId > 0, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->when($from, fn (Builder $query) => $query->whereDate('issue_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $query) => $query->whereDate('issue_date', '<=', $to->toDateString()));
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
