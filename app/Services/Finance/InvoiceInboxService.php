<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Support\Finance\InvoicePresentation;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InvoiceInboxService
{
    /**
     * @return array<string, int>
     */
    public function pipelineCounts(int $workspaceId, string $type = ''): array
    {
        $base = FinanceInvoice::query()->where('workspace_id', $workspaceId);
        if (in_array($type, ['sales', 'purchase'], true)) {
            $base->where('type', $type);
        }

        $counts = ['all' => (clone $base)->count()];
        foreach (InvoicePresentation::pipelineKeys() as $key) {
            $counts[$key] = (clone $base)->tap(fn (Builder $query) => $this->applyLifecycle($query, $key))->count();
        }

        return $counts;
    }

    /**
     * @return array{total:string,due:string,overdue:string}
     */
    public function filteredTotals(Builder $query): array
    {
        $row = (clone $query)
            ->selectRaw('COALESCE(SUM(total),0) as total')
            ->selectRaw('COALESCE(SUM(amount_due),0) as due')
            ->first();

        $overdue = (clone $query)->wherePaymentStatus('overdue')->sum('amount_due');

        return [
            'total' => Money::of($row->total ?? 0),
            'due' => Money::of($row->due ?? 0),
            'overdue' => Money::of($overdue),
        ];
    }

    public function applyRequestFilters(Builder $query, Request $request): Builder
    {
        $hasSplit = FinanceInvoice::hasSeparatedStatusColumns();
        $invoiceStatus = $request->string('invoice_status')->toString();
        $paymentStatus = $request->string('payment_status')->toString();
        $legacyStatus = $request->string('status')->toString();
        $lifecycle = $request->string('lifecycle')->toString();

        if ($legacyStatus !== '' && $invoiceStatus === '' && $paymentStatus === '' && $lifecycle === '') {
            if (in_array($legacyStatus, InvoicePresentation::pipelineKeys(), true)) {
                $lifecycle = $legacyStatus === 'sent' ? 'sent' : $legacyStatus;
            } elseif ($legacyStatus === 'issued') {
                $invoiceStatus = 'issued';
            }
        }

        $query
            ->when($request->string('search')->toString(), function ($inner, $search) use ($hasSplit): void {
                $inner->where(function ($block) use ($search, $hasSplit): void {
                    $block->where('invoice_number', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('status', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$search.'%'));
                    if ($hasSplit) {
                        $block->orWhere('invoice_status', 'like', '%'.$search.'%')
                            ->orWhere('payment_status', 'like', '%'.$search.'%');
                    }
                });
            })
            ->when($request->filled('type'), fn ($inner) => $inner->where('type', $request->string('type')->toString()))
            ->when($request->filled('customer_id'), fn ($inner) => $inner->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('supplier_id'), fn ($inner) => $inner->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('currency'), fn ($inner) => $inner->where('currency', $request->string('currency')->toString()))
            ->when($request->filled('from'), fn ($inner) => $inner->whereDate('issue_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($inner) => $inner->whereDate('issue_date', '<=', $request->string('to')->toString()))
            ->when($request->filled('contract_id') && FinanceInvoice::hasContractColumn(), fn ($inner) => $inner->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('project_id') && Schema::hasColumn('finance_invoices', 'project_id'), fn ($inner) => $inner->where('project_id', $request->integer('project_id')))
            ->when($request->filled('payment_method'), function ($inner) use ($request): void {
                $method = $request->string('payment_method')->toString();
                $inner->whereHas('payments', fn ($payment) => $payment->where('method', $method));
            })
            ->when($invoiceStatus !== '', fn ($inner) => $inner->whereInvoiceStatus($invoiceStatus))
            ->when($paymentStatus !== '', fn ($inner) => $inner->wherePaymentStatus($paymentStatus));

        if ($lifecycle !== '') {
            $this->applyLifecycle($query, $lifecycle);
        }

        return $query;
    }

    public function applyLifecycle(Builder $query, string $lifecycle): Builder
    {
        return match ($lifecycle) {
            InvoicePresentation::LIFECYCLE_DRAFT => $query->whereInvoiceStatus('draft'),
            InvoicePresentation::LIFECYCLE_CANCELLED => $query->whereInvoiceStatus('cancelled'),
            InvoicePresentation::LIFECYCLE_PAID => $query->whereIssued()->wherePaymentStatus('paid'),
            InvoicePresentation::LIFECYCLE_OVERDUE => $query->whereIssued()->wherePaymentStatus('overdue'),
            InvoicePresentation::LIFECYCLE_PARTIAL => $query->whereIssued()->wherePaymentStatus('partial'),
            InvoicePresentation::LIFECYCLE_SENT => $query->whereIssued()->wherePaymentStatus('unpaid'),
            default => $query,
        };
    }
}
