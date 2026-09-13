<?php

namespace App\Support\Finance;

use App\Models\Finance\FinanceInvoice;

/**
 * Display lifecycle for operators. Stored statuses stay invoice_status + payment_status.
 * Sent = issued and not fully paid and not overdue. Overdue and Paid are first-class.
 */
final class InvoicePresentation
{
    public const LIFECYCLE_DRAFT = 'draft';

    public const LIFECYCLE_SENT = 'sent';

    public const LIFECYCLE_PARTIAL = 'partial';

    public const LIFECYCLE_PAID = 'paid';

    public const LIFECYCLE_OVERDUE = 'overdue';

    public const LIFECYCLE_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function lifecycleLabels(): array
    {
        return [
            self::LIFECYCLE_DRAFT => 'مسودة',
            self::LIFECYCLE_SENT => 'مرسلة',
            self::LIFECYCLE_PARTIAL => 'مدفوعة جزئيًا',
            self::LIFECYCLE_PAID => 'مدفوعة',
            self::LIFECYCLE_OVERDUE => 'متأخرة',
            self::LIFECYCLE_CANCELLED => 'ملغاة',
        ];
    }

    public static function lifecycle(FinanceInvoice $invoice): string
    {
        return self::fromStatuses(
            (string) ($invoice->invoice_status ?: $invoice->status),
            (string) ($invoice->payment_status ?: $invoice->status)
        );
    }

    public static function fromStatuses(string $invoiceStatus, string $paymentStatus): string
    {
        if ($invoiceStatus === 'draft' || $invoiceStatus === self::LIFECYCLE_DRAFT) {
            return self::LIFECYCLE_DRAFT;
        }

        if ($invoiceStatus === 'cancelled') {
            return self::LIFECYCLE_CANCELLED;
        }

        return match ($paymentStatus) {
            'paid' => self::LIFECYCLE_PAID,
            'overdue' => self::LIFECYCLE_OVERDUE,
            'partial' => self::LIFECYCLE_PARTIAL,
            default => self::LIFECYCLE_SENT,
        };
    }

    public static function label(string $lifecycle): string
    {
        return self::lifecycleLabels()[$lifecycle] ?? $lifecycle;
    }

    public static function badgeClass(string $lifecycle): string
    {
        return match ($lifecycle) {
            self::LIFECYCLE_DRAFT => 'bg-slate-100 text-slate-600',
            self::LIFECYCLE_SENT => 'bg-indigo-50 text-indigo-700',
            self::LIFECYCLE_PARTIAL => 'bg-sky-50 text-sky-700',
            self::LIFECYCLE_PAID => 'bg-emerald-50 text-emerald-700',
            self::LIFECYCLE_OVERDUE => 'bg-rose-50 text-rose-700',
            self::LIFECYCLE_CANCELLED => 'bg-slate-200 text-slate-500',
            default => 'bg-slate-100 text-slate-700',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function pipelineKeys(): array
    {
        return [
            self::LIFECYCLE_DRAFT,
            self::LIFECYCLE_SENT,
            self::LIFECYCLE_PARTIAL,
            self::LIFECYCLE_PAID,
            self::LIFECYCLE_OVERDUE,
            self::LIFECYCLE_CANCELLED,
        ];
    }
}
