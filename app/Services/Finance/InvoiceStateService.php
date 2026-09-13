<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class InvoiceStateService
{
    public const PAYMENT_TOLERANCE = 0.009;

    public function resolveInvoiceStatus(?string $requestedStatus): string
    {
        $normalized = strtolower(trim((string) $requestedStatus));

        return match ($normalized) {
            'cancelled' => 'cancelled',
            'issued', 'unpaid', 'partial', 'paid', 'overdue', 'sent' => 'issued',
            default => 'draft',
        };
    }

    public function resolvePaymentStatus(
        float $total,
        float $amountPaid,
        ?string $dueDate,
        string $invoiceStatus,
        float $amountCredited = 0,
        float $amountDebited = 0,
    ): string {
        $paid = round(max(0, $amountPaid), 2);
        $due = $this->resolveAmountDue($total, $paid, $amountCredited, $amountDebited);

        if ($due <= self::PAYMENT_TOLERANCE) {
            return 'paid';
        }

        if ($paid > self::PAYMENT_TOLERANCE) {
            return 'partial';
        }

        if (
            $invoiceStatus === 'issued'
            && $due > self::PAYMENT_TOLERANCE
            && $dueDate
            && $this->isPastDate($dueDate)
        ) {
            return 'overdue';
        }

        return 'unpaid';
    }

    public function resolveAmountDue(
        float $total,
        float $amountPaid,
        float $amountCredited = 0,
        float $amountDebited = 0,
    ): float {
        return round(max(0, $total + $amountDebited - $amountCredited - $amountPaid), 2);
    }

    public function netInvoiceTotal(FinanceInvoice $invoice): float
    {
        return round(
            (float) $invoice->total
            + (float) ($invoice->amount_debited ?? 0)
            - (float) ($invoice->amount_credited ?? 0),
            2
        );
    }

    public function toLegacyStatus(string $invoiceStatus, string $paymentStatus): string
    {
        if ($invoiceStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($invoiceStatus === 'draft') {
            return 'draft';
        }

        return in_array($paymentStatus, ['unpaid', 'partial', 'paid', 'overdue'], true)
            ? $paymentStatus
            : 'unpaid';
    }

    /**
     * Recalculate payment status and due amounts for issued invoices.
     * Returns number of updated rows.
     */
    public function refreshIssuedStatuses(?int $workspaceId = null): int
    {
        $context = app(WorkspaceContext::class);
        if ($workspaceId) {
            return $context->runForId($workspaceId, fn (): int => $this->refreshIssuedStatusesBody($workspaceId));
        }

        return $context->runBypassingTenantScope(
            'finance-invoice-status-scheduler',
            fn (): int => $this->refreshIssuedStatusesBody(null),
        );
    }

    private function refreshIssuedStatusesBody(?int $workspaceId = null): int
    {
        $updatedRows = 0;
        $supportsSplitStatuses = FinanceInvoice::hasSeparatedStatusColumns();
        $hasPaymentRowStatus = Schema::hasColumn('finance_invoice_payments', 'status');

        $query = FinanceInvoice::withoutGlobalScopes();
        if ($supportsSplitStatuses) {
            $query->where(function ($builder): void {
                $builder->where('invoice_status', 'issued')
                    ->orWhere(function ($legacyBuilder): void {
                        $legacyBuilder->whereNull('invoice_status')
                            ->whereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue']);
                    });
            });
        } else {
            $query->whereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue']);
        }

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $query->withSum(['payments as payments_sum_amount' => function ($builder) use ($hasPaymentRowStatus): void {
            if ($hasPaymentRowStatus) {
                $builder->where(function ($statusQuery): void {
                    $statusQuery->whereNull('status')->orWhere('status', 'posted');
                });
            }
        }], 'amount')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use (&$updatedRows, $supportsSplitStatuses): void {
                foreach ($invoices as $invoice) {
                    $actualPaid = round((float) ($invoice->payments_sum_amount ?? 0), 2);
                    $credited = (float) ($invoice->amount_credited ?? 0);
                    $debited = (float) ($invoice->amount_debited ?? 0);
                    $due = $this->resolveAmountDue((float) $invoice->total, $actualPaid, $credited, $debited);
                    $paymentStatus = $this->resolvePaymentStatus(
                        total: (float) $invoice->total,
                        amountPaid: $actualPaid,
                        dueDate: $invoice->due_date?->toDateString(),
                        invoiceStatus: 'issued',
                        amountCredited: $credited,
                        amountDebited: $debited,
                    );
                    $legacyStatus = $this->toLegacyStatus('issued', $paymentStatus);

                    $dirty = false;
                    $attributes = [];

                    if ((float) $invoice->amount_paid !== $actualPaid) {
                        $attributes['amount_paid'] = $actualPaid;
                        $dirty = true;
                    }
                    if ((float) $invoice->amount_due !== $due) {
                        $attributes['amount_due'] = $due;
                        $dirty = true;
                    }
                    if ($supportsSplitStatuses && $invoice->payment_status !== $paymentStatus) {
                        $attributes['payment_status'] = $paymentStatus;
                        $dirty = true;
                    }
                    if ($supportsSplitStatuses && empty($invoice->invoice_status)) {
                        $attributes['invoice_status'] = 'issued';
                        $dirty = true;
                    }
                    if ($invoice->status !== $legacyStatus) {
                        $attributes['status'] = $legacyStatus;
                        $dirty = true;
                    }

                    if ($dirty) {
                        app(\App\Support\Tenancy\WorkspaceContext::class)->runForId(
                            (int) $invoice->workspace_id,
                            function () use ($invoice, $attributes): void {
                                $invoice->update($attributes);
                            }
                        );
                        $updatedRows++;
                    }
                }
            });

        return $updatedRows;
    }

    public function postedPaymentsSum(FinanceInvoice $invoice): float
    {
        $query = FinanceInvoicePayment::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('invoice_id', $invoice->id);

        if (Schema::hasColumn('finance_invoice_payments', 'status')) {
            $query->where(function ($builder): void {
                $builder->whereNull('status')->orWhere('status', 'posted');
            });
        }

        return round((float) $query->sum('amount'), 2);
    }

    private function isPastDate(string $dueDate): bool
    {
        try {
            return Carbon::parse($dueDate)->startOfDay()->lt(now()->startOfDay());
        } catch (\Throwable) {
            return false;
        }
    }
}
