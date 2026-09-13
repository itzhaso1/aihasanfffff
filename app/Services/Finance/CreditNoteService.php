<?php

namespace App\Services\Finance;

use App\Enums\Finance\TaxPriceMode;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceCreditNoteItem;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Workspace;
use App\Services\EInvoicing\InvoiceIssueService;
use App\Services\Finance\Tax\TaxCalculationResult;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreditNoteService
{
    public function __construct(
        private readonly TaxCalculationService $taxCalculator,
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly AccountingService $accountingService,
        private readonly InvoiceStateService $invoiceStateService,
        private readonly InvoiceService $invoiceService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
        private readonly IssuedSnapshotBuilder $issuedSnapshotBuilder,
        private readonly InvoiceIssueService $invoiceIssueService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(Workspace $workspace, FinanceInvoice $invoice, array $payload, int $actorUserId): FinanceCreditNote
    {
        $invoiceStatus = $invoice->invoice_status
            ?? $this->invoiceStateService->resolveInvoiceStatus($invoice->status);
        if ($invoiceStatus !== 'issued') {
            throw new RuntimeException('إشعارات الدائن/المدين تصدر فقط على فاتورة معتمدة.');
        }

        $type = (string) ($payload['type'] ?? FinanceCreditNote::TYPE_CREDIT);
        if (! in_array($type, [FinanceCreditNote::TYPE_CREDIT, FinanceCreditNote::TYPE_DEBIT], true)) {
            throw new RuntimeException('نوع الإشعار غير صالح.');
        }

        return DB::transaction(function () use ($workspace, $invoice, $payload, $actorUserId, $type): FinanceCreditNote {
            $lockedInvoice = FinanceInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $profile = [
                'type' => $this->taxCalculator->normalizeProfileType(
                    (string) ($payload['tax_profile_type'] ?? $lockedInvoice->tax_profile_type ?? 'standard'),
                    (string) ($lockedInvoice->tax_profile_type ?? 'standard')
                ),
                'rate' => (float) ($payload['tax_rate'] ?? $lockedInvoice->tax_rate ?? 0),
            ];
            $priceMode = TaxPriceMode::tryFrom((string) ($payload['tax_price_mode'] ?? $lockedInvoice->tax_price_mode ?? TaxPriceMode::Exclusive->value))
                ?? TaxPriceMode::Exclusive;
            $items = $this->normalizeItems($workspace, $payload['items'] ?? [], $profile['type'], $profile['rate'], $priceMode);
            if ($items === []) {
                throw new RuntimeException('يجب أن يحتوي الإشعار على بند واحد على الأقل.');
            }

            $result = $items['result'];
            $normalizedItems = $items['items'];
            $totals = $result->totalsArray();
            $headerProfile = $result->headerProfile($profile);
            $this->assertCreditDoesNotExceedRemaining($lockedInvoice, $type, $totals['total']);

            $noteAttributes = [
                'workspace_id' => $workspace->id,
                'invoice_id' => $lockedInvoice->id,
                'customer_id' => $lockedInvoice->customer_id,
                'note_number' => $this->nextNumber($workspace->id, $type),
                'type' => $type,
                'status' => FinanceCreditNote::STATUS_DRAFT,
                'reason' => ($payload['reason'] ?? null) ?: null,
                'issue_date' => (string) ($payload['issue_date'] ?? now()->toDateString()),
                'currency' => (string) ($payload['currency'] ?? $lockedInvoice->currency ?? 'SAR'),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'tax_profile_type' => $headerProfile['type'],
                'tax_rate' => round($headerProfile['rate'], 2),
                'notes' => ($payload['notes'] ?? null) ?: null,
                'created_by' => $actorUserId,
            ];

            if (FinanceCreditNote::hasTaxEngineColumns()) {
                $noteAttributes['tax_price_mode'] = $result->priceMode->value;
                $noteAttributes['tax_breakdown'] = $result->categoryTotalsToArray();
            }

            $note = FinanceCreditNote::withoutGlobalScopes()->create($noteAttributes);

            foreach ($normalizedItems as $item) {
                $itemAttributes = [
                    'workspace_id' => $workspace->id,
                    'credit_note_id' => $note->id,
                    'product_name' => $item['product_name'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $item['tax_amount'],
                    'taxable_amount' => $item['taxable_amount'],
                    'total' => $item['total'],
                    'metadata' => $item['metadata'],
                ];
                if (FinanceCreditNoteItem::hasTaxProfileColumn()) {
                    $itemAttributes['tax_profile_type'] = $item['tax_profile_type'];
                }
                if (FinanceCreditNoteItem::hasExemptionColumns()) {
                    $itemAttributes['exemption_reason'] = $item['exemption_reason'] ?? null;
                    $itemAttributes['exemption_code'] = $item['exemption_code'] ?? null;
                }
                if (FinanceCreditNoteItem::hasUnitCodeColumn()) {
                    $itemAttributes['unit_code'] = $item['unit_code'] ?? null;
                }
                FinanceCreditNoteItem::withoutGlobalScopes()->create($itemAttributes);
            }

            if (($payload['status'] ?? 'draft') === 'issued') {
                return $this->issue($note->fresh(['items']), $actorUserId);
            }

            return $note->fresh(['items', 'invoice']);
        });
    }

    public function issue(FinanceCreditNote $note, int $actorUserId): FinanceCreditNote
    {
        return DB::transaction(function () use ($note, $actorUserId): FinanceCreditNote {
            $locked = FinanceCreditNote::withoutGlobalScopes()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === FinanceCreditNote::STATUS_ISSUED) {
                $issued = $locked->fresh(['items', 'invoice', 'customer']);
                $this->connectIssuedNote($issued);

                return $issued;
            }
            if ($locked->status === FinanceCreditNote::STATUS_CANCELLED) {
                throw new RuntimeException('لا يمكن إصدار إشعار ملغى.');
            }

            $invoice = FinanceInvoice::withoutGlobalScopes()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
            $this->assertCreditDoesNotExceedRemaining($invoice, (string) $locked->type, (float) $locked->total);
            $this->assertIssuedTaxReconciles($locked);
            $this->financialPeriodGuardService->assertDateIsOpen(
                workspaceId: (int) $locked->workspace_id,
                date: $locked->issue_date?->toDateString() ?? now()->toDateString(),
                context: 'إصدار إشعار مالي'
            );

            $this->postNoteEntry($locked, $invoice, $actorUserId);

            $locked->update([
                'status' => FinanceCreditNote::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by' => $actorUserId,
            ]);

            $this->applyToInvoice($invoice, $locked);

            $issued = $locked->fresh(['items', 'invoice', 'customer']);
            $this->connectIssuedNote($issued);

            return $issued;
        });
    }

    private function connectIssuedNote(FinanceCreditNote $note): void
    {
        $snapshot = $this->issuedSnapshotBuilder->captureCreditNote($note);
        $this->invoiceIssueService->prepareFromSnapshot($snapshot);
    }

    public function cancel(FinanceCreditNote $note, int $actorUserId): FinanceCreditNote
    {
        return DB::transaction(function () use ($note, $actorUserId): FinanceCreditNote {
            $locked = FinanceCreditNote::withoutGlobalScopes()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === FinanceCreditNote::STATUS_CANCELLED) {
                return $locked;
            }

            if ($locked->status === FinanceCreditNote::STATUS_ISSUED) {
                $entry = FinanceJournalEntry::withoutGlobalScopes()
                    ->where('workspace_id', $locked->workspace_id)
                    ->whereIn('type', ['credit_note', 'debit_note'])
                    ->where('reference_type', FinanceCreditNote::class)
                    ->where('reference_id', $locked->id)
                    ->whereNull('reverses_entry_id')
                    ->latest('id')
                    ->first();
                if ($entry) {
                    $this->accountingService->reverseEntry(
                        entry: $entry,
                        type: $locked->isCredit() ? 'invoice_reversal' : 'invoice_reversal',
                        actorUserId: $actorUserId,
                        description: 'عكس الإشعار '.$locked->note_number,
                        referenceType: FinanceCreditNote::class,
                        referenceId: $locked->id,
                    );
                }

                $invoice = FinanceInvoice::withoutGlobalScopes()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
                $credited = (float) ($invoice->amount_credited ?? 0);
                $debited = (float) ($invoice->amount_debited ?? 0);
                if ($locked->isCredit()) {
                    $credited = max(0, $credited - (float) $locked->total);
                } else {
                    $debited = max(0, $debited - (float) $locked->total);
                }
                $invoice->update([
                    'amount_credited' => round($credited, 2),
                    'amount_debited' => round($debited, 2),
                ]);
                $this->invoiceService->syncPaymentStatus($invoice->fresh());
            }

            $locked->update([
                'status' => FinanceCreditNote::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            return $locked->fresh(['items', 'invoice']);
        });
    }

    private function applyToInvoice(FinanceInvoice $invoice, FinanceCreditNote $note): void
    {
        $credited = (float) ($invoice->amount_credited ?? 0);
        $debited = (float) ($invoice->amount_debited ?? 0);
        if ($note->isCredit()) {
            $credited = round($credited + (float) $note->total, 2);
        } else {
            $debited = round($debited + (float) $note->total, 2);
        }

        $invoice->update([
            'amount_credited' => $credited,
            'amount_debited' => $debited,
        ]);

        $this->invoiceService->syncPaymentStatus($invoice->fresh());
    }

    private function assertCreditDoesNotExceedRemaining(FinanceInvoice $invoice, string $type, float $amount): void
    {
        if ($type !== FinanceCreditNote::TYPE_CREDIT) {
            return;
        }

        $paid = $this->invoiceStateService->postedPaymentsSum($invoice);
        $remaining = $this->invoiceStateService->resolveAmountDue(
            (float) $invoice->total,
            $paid,
            (float) ($invoice->amount_credited ?? 0),
            (float) ($invoice->amount_debited ?? 0),
        );

        if ($amount - $remaining > InvoiceStateService::PAYMENT_TOLERANCE) {
            throw new RuntimeException('قيمة إشعار الدائن لا يمكن أن تتجاوز المتبقي على الفاتورة.');
        }
    }

    private function postNoteEntry(FinanceCreditNote $note, FinanceInvoice $invoice, int $actorUserId): void
    {
        $alreadyPosted = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $note->workspace_id)
            ->whereIn('type', ['credit_note', 'debit_note'])
            ->where('reference_type', FinanceCreditNote::class)
            ->where('reference_id', $note->id)
            ->whereNull('reverses_entry_id')
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        $ar = $this->chartOfAccountsService->byCode('1200');
        $ap = $this->chartOfAccountsService->byCode('2000');
        $sales = $this->chartOfAccountsService->byCode('4000');
        $expense = $this->chartOfAccountsService->byCode('5900');
        $outputVat = $this->chartOfAccountsService->byCode('2100');
        $inputVat = $this->chartOfAccountsService->byCode('1400');
        if (! $ar || ! $ap || ! $sales || ! $expense || ! $outputVat || ! $inputVat) {
            throw new RuntimeException('دليل الحسابات غير مكتمل ولا يمكن ترحيل الإشعار.');
        }

        $isSales = $invoice->type === 'sales';
        $isCredit = $note->isCredit();
        $taxable = (float) $note->taxable_amount;
        $tax = (float) $note->tax_amount;
        $total = (float) $note->total;

        if ($isSales && $isCredit) {
            $lines = [
                ['account_id' => $sales->id, 'debit' => $taxable, 'credit' => 0, 'description' => 'Credit note revenue reversal'],
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $outputVat->id, 'debit' => $tax, 'credit' => 0, 'description' => 'Credit note VAT reversal'];
            }
            $lines[] = ['account_id' => $ar->id, 'debit' => 0, 'credit' => $total, 'description' => 'Reduce receivable'];
        } elseif ($isSales && ! $isCredit) {
            $lines = [
                ['account_id' => $ar->id, 'debit' => $total, 'credit' => 0, 'description' => 'Increase receivable'],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => $taxable, 'description' => 'Debit note revenue'],
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $outputVat->id, 'debit' => 0, 'credit' => $tax, 'description' => 'Debit note VAT'];
            }
        } elseif (! $isSales && $isCredit) {
            $lines = [
                ['account_id' => $ap->id, 'debit' => $total, 'credit' => 0, 'description' => 'Reduce payable'],
                ['account_id' => $expense->id, 'debit' => 0, 'credit' => $taxable, 'description' => 'Credit note expense reversal'],
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $inputVat->id, 'debit' => 0, 'credit' => $tax, 'description' => 'Credit note input VAT reversal'];
            }
        } else {
            $lines = [
                ['account_id' => $expense->id, 'debit' => $taxable, 'credit' => 0, 'description' => 'Debit note additional expense'],
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $inputVat->id, 'debit' => $tax, 'credit' => 0, 'description' => 'Debit note input VAT'];
            }
            $lines[] = ['account_id' => $ap->id, 'debit' => 0, 'credit' => $total, 'description' => 'Increase payable'];
        }

        foreach ($lines as &$line) {
            $line['entity_type'] = FinanceCreditNote::class;
            $line['entity_id'] = $note->id;
        }
        unset($line);

        $this->accountingService->createEntry(
            workspaceId: (int) $note->workspace_id,
            entryDate: $note->issue_date?->toDateString() ?? now()->toDateString(),
            type: $isCredit ? 'credit_note' : 'debit_note',
            lines: $lines,
            description: ($isCredit ? 'Credit' : 'Debit').' note '.$note->note_number.' for invoice '.$invoice->invoice_number,
            referenceType: FinanceCreditNote::class,
            referenceId: $note->id,
            postedBy: $actorUserId
        );
    }

    /**
     * @param  array<int, mixed>  $rawItems
     * @return array{items: array<int, array<string, mixed>>, result: TaxCalculationResult|null}
     */
    private function normalizeItems(
        Workspace $workspace,
        array $rawItems,
        string $taxType,
        float $defaultTaxRate,
        TaxPriceMode $priceMode,
    ): array {
        $prepared = [];
        foreach ($rawItems as $rawItem) {
            if (! is_array($rawItem)) {
                continue;
            }
            $name = trim((string) ($rawItem['product_name'] ?? $rawItem['title'] ?? ''));
            $quantity = (float) ($rawItem['quantity'] ?? 0);
            $unitPrice = (float) ($rawItem['unit_price'] ?? 0);
            if ($name === '' && $quantity <= 0 && $unitPrice <= 0) {
                continue;
            }

            $line = [
                'product_name' => $name !== '' ? $name : 'بند',
                'description' => $rawItem['description'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => (float) ($rawItem['discount'] ?? 0),
                'tax_type' => $rawItem['tax_type'] ?? $rawItem['tax_profile_type'] ?? $taxType,
                'tax_profile_type' => $rawItem['tax_profile_type'] ?? $rawItem['tax_type'] ?? $taxType,
                'exemption_reason' => $rawItem['exemption_reason'] ?? null,
                'exemption_code' => $rawItem['exemption_code'] ?? null,
                'unit_code' => $this->nullableCode($rawItem['unit_code'] ?? null),
                'metadata' => is_array($rawItem['metadata'] ?? null) ? $rawItem['metadata'] : null,
            ];
            if (array_key_exists('tax_rate', $rawItem)) {
                $line['tax_rate'] = $rawItem['tax_rate'];
            }
            $prepared[] = $line;
        }

        if ($prepared === []) {
            return ['items' => [], 'result' => null];
        }

        $result = $this->taxCalculator->calculateDocument(
            $workspace,
            $prepared,
            $priceMode,
            $taxType,
            $defaultTaxRate,
        );

        $items = [];
        foreach ($result->lines as $index => $line) {
            $source = $prepared[$index];
            $items[] = [
                'product_name' => $source['product_name'],
                'description' => $source['description'],
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'discount' => $line->discountAmount,
                'tax_profile_type' => $line->classification->value,
                'tax_rate' => $line->taxRate,
                'tax_amount' => $line->taxAmount,
                'taxable_amount' => $line->taxableAmount,
                'total' => $line->total,
                'exemption_reason' => $line->exemptionReason,
                'exemption_code' => $line->exemptionCode,
                'unit_code' => $source['unit_code'] ?? null,
                'metadata' => $source['metadata'],
            ];
        }

        return ['items' => $items, 'result' => $result];
    }

    private function assertIssuedTaxReconciles(FinanceCreditNote $note): void
    {
        $note->loadMissing('items');
        $workspace = Workspace::query()->findOrFail((int) $note->workspace_id);
        $priceMode = TaxPriceMode::tryFrom((string) ($note->tax_price_mode ?? TaxPriceMode::Exclusive->value))
            ?? TaxPriceMode::Exclusive;
        $result = $this->taxCalculator->calculateFromPersistedLines($workspace, $note->items, $priceMode);
        if (! $result->matchesPersistedCreditNote($note)) {
            throw new RuntimeException('نتيجة الضريبة غير متسقة ولا يمكن إصدار الإشعار.');
        }

        if (FinanceCreditNote::hasTaxEngineColumns() && $note->tax_breakdown === null) {
            $note->update([
                'tax_breakdown' => $result->categoryTotalsToArray(),
                'tax_price_mode' => $result->priceMode->value,
            ]);
        }
    }

    private function nextNumber(int $workspaceId, string $type): string
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
                'credit_note_prefix' => 'CN',
                'next_credit_note_sequence' => 1,
                'debit_note_prefix' => 'DN',
                'next_debit_note_sequence' => 1,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]);
        }

        $isCredit = $type === FinanceCreditNote::TYPE_CREDIT;
        $prefix = $isCredit
            ? ($settings->credit_note_prefix ?: 'CN')
            : ($settings->debit_note_prefix ?: 'DN');
        $sequence = (int) ($isCredit ? ($settings->next_credit_note_sequence ?? 1) : ($settings->next_debit_note_sequence ?? 1));
        $number = sprintf('%s-%06d', $prefix, $sequence);

        $settings->update($isCredit
            ? ['next_credit_note_sequence' => $sequence + 1]
            : ['next_debit_note_sequence' => $sequence + 1]);

        return $number;
    }

    private function nullableCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $code = is_string($value) || is_numeric($value) ? (string) $value : null;

        return $code === '' ? null : $code;
    }
}
