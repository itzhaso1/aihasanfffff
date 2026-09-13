<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceReceipt;
use App\Models\Finance\FinanceSetting;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReceiptService
{
    public function ensureForPostedPayment(
        FinanceInvoicePayment $payment,
        FinanceInvoice $invoice,
        int $actorUserId,
    ): ?FinanceReceipt {
        if (! Schema::hasTable('finance_receipts')) {
            return null;
        }

        if ((string) $invoice->type !== 'sales') {
            return null;
        }

        if (! $payment->isPosted()) {
            return null;
        }

        $existing = FinanceReceipt::withoutGlobalScopes()
            ->where('payment_id', $payment->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return $this->createFromPayment($payment, $invoice, $actorUserId);
        } catch (UniqueConstraintViolationException) {
            return FinanceReceipt::withoutGlobalScopes()
                ->where('payment_id', $payment->id)
                ->first();
        }
    }

    public function voidForPayment(FinanceInvoicePayment $payment, int $actorUserId): ?FinanceReceipt
    {
        if (! Schema::hasTable('finance_receipts')) {
            return null;
        }

        $receipt = FinanceReceipt::withoutGlobalScopes()
            ->where('payment_id', $payment->id)
            ->lockForUpdate()
            ->first();

        if (! $receipt || $receipt->isVoided()) {
            return $receipt;
        }

        $receipt->update([
            'status' => FinanceReceipt::STATUS_VOIDED,
            'voided_at' => now(),
            'voided_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        return $receipt->fresh();
    }

    private function createFromPayment(
        FinanceInvoicePayment $payment,
        FinanceInvoice $invoice,
        int $actorUserId,
    ): FinanceReceipt {
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Receipt amount must come from a posted payment greater than zero.');
        }

        return FinanceReceipt::withoutGlobalScopes()->create([
            'workspace_id' => $invoice->workspace_id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'receipt_number' => $this->nextReceiptNumber((int) $invoice->workspace_id),
            'currency' => strtoupper((string) ($invoice->currency ?: 'SAR')),
            'payment_date' => $payment->payment_date?->toDateString() ?? now()->toDateString(),
            'method' => (string) ($payment->method ?: 'cash'),
            'reference' => $payment->reference,
            'amount' => $amount,
            'status' => FinanceReceipt::STATUS_POSTED,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);
    }

    private function nextReceiptNumber(int $workspaceId): string
    {
        $settings = FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $settings) {
            $settings = FinanceSetting::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'currency' => 'SAR',
                'country_code' => 'SA',
                'invoice_prefix' => 'INV',
                'next_invoice_sequence' => 1,
                'receipt_prefix' => 'RCT',
                'next_receipt_sequence' => 1,
                'allow_manual_invoice_numbers' => false,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]);
        }

        $prefix = Schema::hasColumn('finance_settings', 'receipt_prefix')
            ? (string) ($settings->receipt_prefix ?: 'RCT')
            : 'RCT';
        $sequence = Schema::hasColumn('finance_settings', 'next_receipt_sequence')
            ? max(1, (int) $settings->next_receipt_sequence)
            : 1;
        $year = now(config('app.timezone'))->format('Y');
        $attempts = 0;
        $number = '';
        $exists = true;

        while ($exists && $attempts < 100) {
            $number = sprintf('%s-%s-%04d', $prefix, $year, $sequence);
            $exists = FinanceReceipt::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('receipt_number', $number)
                ->exists();
            $sequence++;
            $attempts++;
        }

        if ($exists || $number === '') {
            throw new RuntimeException('تعذر توليد رقم إيصال فريد لهذه المنشأة.');
        }

        if (Schema::hasColumn('finance_settings', 'next_receipt_sequence')) {
            $settings->update(['next_receipt_sequence' => $sequence]);
        }

        return $number;
    }
}
