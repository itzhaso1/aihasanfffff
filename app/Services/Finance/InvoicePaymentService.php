<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceTreasuryAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoicePaymentService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly InvoiceStateService $invoiceStateService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
        private readonly TreasuryBalanceService $treasuryBalanceService,
        private readonly ReceiptService $receiptService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordPayment(FinanceInvoice $invoice, array $payload, int $actorUserId): FinanceInvoicePayment
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($invoice, $payload, $amount, $actorUserId): FinanceInvoicePayment {
            $lockedInvoice = FinanceInvoice::withoutGlobalScopes()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvoice) {
                throw new RuntimeException('Invoice not found.');
            }

            $invoiceStatus = $lockedInvoice->invoice_status
                ?? $this->invoiceStateService->resolveInvoiceStatus($lockedInvoice->status);

            if ($invoiceStatus !== 'issued') {
                throw new RuntimeException('Payments are allowed only for issued invoices.');
            }

            $reference = trim((string) ($payload['reference'] ?? ''));
            if ($reference !== '') {
                $existingPayment = FinanceInvoicePayment::withoutGlobalScopes()
                    ->where('workspace_id', $lockedInvoice->workspace_id)
                    ->where('invoice_id', $lockedInvoice->id)
                    ->where('reference', $reference)
                    ->first();

                if ($existingPayment) {
                    $this->receiptService->ensureForPostedPayment($existingPayment, $lockedInvoice, $actorUserId);

                    return $existingPayment;
                }
            }

            $existingPaid = $this->invoiceStateService->postedPaymentsSum($lockedInvoice);
            $credited = (float) ($lockedInvoice->amount_credited ?? 0);
            $debited = (float) ($lockedInvoice->amount_debited ?? 0);

            $remaining = $this->invoiceStateService->resolveAmountDue(
                (float) $lockedInvoice->total,
                $existingPaid,
                $credited,
                $debited
            );
            if ($remaining <= InvoiceStateService::PAYMENT_TOLERANCE) {
                throw new RuntimeException('Invoice is already paid.');
            }

            if ($amount - $remaining > InvoiceStateService::PAYMENT_TOLERANCE) {
                throw new RuntimeException('Payment amount cannot exceed invoice due amount.');
            }

            $paymentDate = (string) ($payload['payment_date'] ?? now()->toDateString());
            $this->financialPeriodGuardService->assertDateIsOpen(
                workspaceId: (int) $lockedInvoice->workspace_id,
                date: $paymentDate,
                context: 'تسجيل دفعة فاتورة'
            );

            $treasuryAccount = null;
            if (! empty($payload['treasury_account_id'])) {
                $treasuryAccount = FinanceTreasuryAccount::withoutGlobalScopes()
                    ->where('workspace_id', $lockedInvoice->workspace_id)
                    ->whereKey((int) $payload['treasury_account_id'])
                    ->first();
            }

            if (! $treasuryAccount) {
                $treasuryAccount = FinanceTreasuryAccount::withoutGlobalScopes()
                    ->where('workspace_id', $lockedInvoice->workspace_id)
                    ->where('type', 'bank')
                    ->orderBy('id')
                    ->first()
                    ?? FinanceTreasuryAccount::withoutGlobalScopes()
                        ->where('workspace_id', $lockedInvoice->workspace_id)
                        ->where('type', 'cash')
                        ->orderBy('id')
                        ->first();
            }

            try {
                $payment = FinanceInvoicePayment::withoutGlobalScopes()->create([
                    'workspace_id' => $lockedInvoice->workspace_id,
                    'invoice_id' => $lockedInvoice->id,
                    'treasury_account_id' => $treasuryAccount?->id,
                    'payment_date' => $paymentDate,
                    'method' => $payload['method'] ?? 'cash',
                    'reference' => $reference !== '' ? $reference : null,
                    'amount' => $amount,
                    'status' => FinanceInvoicePayment::STATUS_POSTED,
                    'notes' => $payload['notes'] ?? null,
                    'created_by' => $actorUserId,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($reference === '') {
                    throw $exception;
                }

                $existingPayment = FinanceInvoicePayment::withoutGlobalScopes()
                    ->where('workspace_id', $lockedInvoice->workspace_id)
                    ->where('invoice_id', $lockedInvoice->id)
                    ->where('reference', $reference)
                    ->first();

                if ($existingPayment) {
                    $this->receiptService->ensureForPostedPayment($existingPayment, $lockedInvoice, $actorUserId);

                    return $existingPayment;
                }

                throw $exception;
            }

            $paid = $this->invoiceStateService->postedPaymentsSum($lockedInvoice);
            $due = $this->invoiceStateService->resolveAmountDue(
                (float) $lockedInvoice->total,
                $paid,
                $credited,
                $debited
            );
            $paymentStatus = $this->invoiceStateService->resolvePaymentStatus(
                total: (float) $lockedInvoice->total,
                amountPaid: $paid,
                dueDate: $lockedInvoice->due_date?->toDateString(),
                invoiceStatus: $invoiceStatus,
                amountCredited: $credited,
                amountDebited: $debited,
            );
            $legacyStatus = $this->invoiceStateService->toLegacyStatus($invoiceStatus, $paymentStatus);

            $lockedInvoice->update([
                'amount_paid' => $paid,
                'amount_due' => $due,
                'status' => $legacyStatus,
            ] + (FinanceInvoice::hasSeparatedStatusColumns() ? [
                'invoice_status' => $invoiceStatus,
                'payment_status' => $paymentStatus,
            ] : []));

            $this->postPaymentEntry($lockedInvoice, $payment, $treasuryAccount?->id, $actorUserId);

            if ($treasuryAccount) {
                $this->treasuryBalanceService->adjust($treasuryAccount, $amount);
            }

            $this->receiptService->ensureForPostedPayment($payment, $lockedInvoice, $actorUserId);

            return $payment;
        });
    }

    public function reversePayment(FinanceInvoicePayment $payment, int $actorUserId, ?string $reason = null): FinanceInvoicePayment
    {
        return DB::transaction(function () use ($payment, $actorUserId, $reason): FinanceInvoicePayment {
            $lockedPayment = FinanceInvoicePayment::withoutGlobalScopes()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPayment->isPosted()) {
                return $lockedPayment;
            }

            $lockedInvoice = FinanceInvoice::withoutGlobalScopes()
                ->whereKey($lockedPayment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->financialPeriodGuardService->assertDateIsOpen(
                workspaceId: (int) $lockedInvoice->workspace_id,
                date: now()->toDateString(),
                context: 'عكس دفعة فاتورة'
            );

            $entry = FinanceJournalEntry::withoutGlobalScopes()
                ->where('workspace_id', $lockedInvoice->workspace_id)
                ->where('type', 'invoice_payment')
                ->where('reference_type', FinanceInvoicePayment::class)
                ->where('reference_id', $lockedPayment->id)
                ->latest('id')
                ->first();

            if ($entry) {
                $this->accountingService->reverseEntry(
                    entry: $entry,
                    type: 'payment_reversal',
                    actorUserId: $actorUserId,
                    description: 'عكس دفعة الفاتورة '.$lockedInvoice->invoice_number,
                    referenceType: FinanceInvoicePayment::class,
                    referenceId: $lockedPayment->id,
                );
            }

            if ($lockedPayment->treasury_account_id) {
                $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                    ->where('workspace_id', $lockedInvoice->workspace_id)
                    ->whereKey($lockedPayment->treasury_account_id)
                    ->lockForUpdate()
                    ->first();
                if ($treasury) {
                    $this->treasuryBalanceService->adjust($treasury, -1 * (float) $lockedPayment->amount);
                }
            }

            $lockedPayment->update([
                'status' => FinanceInvoicePayment::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actorUserId,
                'reversal_reason' => $reason,
            ]);

            $invoiceStatus = $lockedInvoice->invoice_status
                ?? $this->invoiceStateService->resolveInvoiceStatus($lockedInvoice->status);
            $paid = $this->invoiceStateService->postedPaymentsSum($lockedInvoice);
            $credited = (float) ($lockedInvoice->amount_credited ?? 0);
            $debited = (float) ($lockedInvoice->amount_debited ?? 0);
            $due = $this->invoiceStateService->resolveAmountDue((float) $lockedInvoice->total, $paid, $credited, $debited);
            $paymentStatus = $this->invoiceStateService->resolvePaymentStatus(
                total: (float) $lockedInvoice->total,
                amountPaid: $paid,
                dueDate: $lockedInvoice->due_date?->toDateString(),
                invoiceStatus: $invoiceStatus,
                amountCredited: $credited,
                amountDebited: $debited,
            );

            $lockedInvoice->update([
                'amount_paid' => $paid,
                'amount_due' => $due,
                'status' => $this->invoiceStateService->toLegacyStatus($invoiceStatus, $paymentStatus),
            ] + (FinanceInvoice::hasSeparatedStatusColumns() ? [
                'invoice_status' => $invoiceStatus,
                'payment_status' => $paymentStatus,
            ] : []));

            $this->receiptService->voidForPayment($lockedPayment, $actorUserId);

            return $lockedPayment->fresh();
        });
    }

    private function postPaymentEntry(FinanceInvoice $invoice, FinanceInvoicePayment $payment, ?int $treasuryAccountId, int $actorUserId): void
    {
        $alreadyPosted = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('type', 'invoice_payment')
            ->where('reference_type', FinanceInvoicePayment::class)
            ->where('reference_id', $payment->id)
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        $ar = $this->chartOfAccountsService->byCode('1200');
        $ap = $this->chartOfAccountsService->byCode('2000');
        $cash = $this->chartOfAccountsService->byCode('1000');
        $bank = $this->chartOfAccountsService->byCode('1100');

        if (! $ar || ! $ap || ! $cash || ! $bank) {
            throw new RuntimeException('Chart of accounts is incomplete.');
        }

        $targetDrOrCr = $cash;
        if ($treasuryAccountId) {
            $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                ->where('workspace_id', $invoice->workspace_id)
                ->whereKey($treasuryAccountId)
                ->first();
            if ($treasury?->linked_finance_account_id) {
                $linked = $treasury->linkedAccount;
                if ($linked) {
                    $targetDrOrCr = $linked;
                }
            } elseif ($treasury?->type === 'bank') {
                $targetDrOrCr = $bank;
            }
        }

        $amount = (float) $payment->amount;
        if ($invoice->type === 'sales') {
            $lines = [
                [
                    'account_id' => $targetDrOrCr->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Invoice payment received',
                    'entity_type' => FinanceInvoicePayment::class,
                    'entity_id' => $payment->id,
                ],
                [
                    'account_id' => $ar->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Reduce accounts receivable',
                    'entity_type' => FinanceInvoicePayment::class,
                    'entity_id' => $payment->id,
                ],
            ];
        } else {
            $lines = [
                [
                    'account_id' => $ap->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Reduce accounts payable',
                    'entity_type' => FinanceInvoicePayment::class,
                    'entity_id' => $payment->id,
                ],
                [
                    'account_id' => $targetDrOrCr->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Cash/Bank paid out',
                    'entity_type' => FinanceInvoicePayment::class,
                    'entity_id' => $payment->id,
                ],
            ];
        }

        $this->accountingService->createEntry(
            workspaceId: (int) $invoice->workspace_id,
            entryDate: $payment->payment_date?->toDateString() ?? now()->toDateString(),
            type: 'invoice_payment',
            lines: $lines,
            description: 'Payment for invoice '.$invoice->invoice_number,
            referenceType: FinanceInvoicePayment::class,
            referenceId: $payment->id,
            postedBy: $actorUserId
        );
    }
}
