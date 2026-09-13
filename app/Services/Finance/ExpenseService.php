<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceExpenseCategory;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Workspace;
use App\Support\Uploads\SecureUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpenseService
{
    public function __construct(
        private readonly TaxService $taxService,
        private readonly AccountingService $accountingService,
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
        private readonly TreasuryBalanceService $treasuryBalanceService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload, int $actorUserId): FinanceExpense
    {
        $this->chartOfAccountsService->ensureDefaultAccounts($workspace);

        return DB::transaction(function () use ($workspace, $payload, $actorUserId): FinanceExpense {
            $supplier = null;
            if (! empty($payload['supplier_id'])) {
                $supplier = FinanceSupplier::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey((int) $payload['supplier_id'])
                    ->first();
                if (! $supplier) {
                    throw new RuntimeException('Supplier is invalid for this workspace.');
                }
            }

            $category = null;
            if (! empty($payload['category_id'])) {
                $category = FinanceExpenseCategory::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey((int) $payload['category_id'])
                    ->first();
                if (! $category) {
                    throw new RuntimeException('Expense category is invalid for this workspace.');
                }
            }

            $treasury = null;
            if (! empty($payload['treasury_account_id'])) {
                $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey((int) $payload['treasury_account_id'])
                    ->first();
                if (! $treasury) {
                    throw new RuntimeException('Treasury account is invalid for this workspace.');
                }
            }

            $amount = round((float) ($payload['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('Expense amount must be greater than zero.');
            }

            $taxType = (string) ($payload['tax_profile_type'] ?? 'standard');
            $taxRate = (float) ($payload['tax_rate'] ?? 0);
            $calc = $this->taxService->calculateAmount($amount, $taxType, $taxRate);
            $status = (string) ($payload['status'] ?? 'approved');
            $expenseDate = (string) ($payload['expense_date'] ?? now()->toDateString());

            $attachmentPath = null;
            if (($payload['attachment_file'] ?? null) instanceof UploadedFile) {
                $attachment = $payload['attachment_file'];
                $attachmentPath = app(SecureUpload::class)->store(
                    $attachment,
                    'workspaces/'.$workspace->id.'/finance/expenses',
                    'public',
                    4096
                );
            }

            $expense = FinanceExpense::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'supplier_id' => $supplier?->id,
                'category_id' => $category?->id,
                'treasury_account_id' => $treasury?->id,
                'expense_number' => ($payload['expense_number'] ?? null) ?: $this->nextExpenseNumber($workspace->id),
                'expense_date' => $expenseDate,
                'description' => $payload['description'] ?? null,
                'amount' => $amount,
                'tax_rate' => $taxRate,
                'tax_amount' => $calc['tax_amount'],
                'total' => $calc['total'],
                'currency' => (string) ($payload['currency'] ?? 'SAR'),
                'payment_method' => (string) ($payload['payment_method'] ?? 'cash'),
                'status' => $status,
                'is_recurring' => (bool) ($payload['is_recurring'] ?? false),
                'recurring_frequency' => $payload['recurring_frequency'] ?? null,
                'next_due_date' => $payload['next_due_date'] ?? null,
                'attachment_path' => $attachmentPath,
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
                'created_by' => $actorUserId,
            ]);

            if (! in_array($status, ['draft', 'cancelled'], true)) {
                $this->financialPeriodGuardService->assertDateIsOpen(
                    workspaceId: $workspace->id,
                    date: $expenseDate,
                    context: 'ترحيل مصروف'
                );
                $this->postExpenseEntry($expense, $category, $treasury, $actorUserId);
            }

            return $expense;
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateDraft(FinanceExpense $expense, array $payload, int $actorUserId): FinanceExpense
    {
        return DB::transaction(function () use ($expense, $payload): FinanceExpense {
            $locked = FinanceExpense::withoutGlobalScopes()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new RuntimeException('يمكن تعديل مسودات المصروفات غير المرحّلة فقط.');
            }

            $amount = round((float) ($payload['amount'] ?? $locked->amount), 2);
            if ($amount <= 0) {
                throw new RuntimeException('Expense amount must be greater than zero.');
            }

            $taxType = (string) ($payload['tax_profile_type'] ?? 'standard');
            $taxRate = (float) ($payload['tax_rate'] ?? $locked->tax_rate);
            $calc = $this->taxService->calculateAmount($amount, $taxType, $taxRate);

            $attachmentPath = $locked->attachment_path;
            if (($payload['attachment_file'] ?? null) instanceof UploadedFile) {
                if ($attachmentPath) {
                    app(SecureUpload::class)->delete($attachmentPath);
                }
                $attachmentPath = app(SecureUpload::class)->store(
                    $payload['attachment_file'],
                    'workspaces/'.$locked->workspace_id.'/finance/expenses',
                    'public',
                    4096
                );
            }

            $supplierId = $locked->supplier_id;
            if (array_key_exists('supplier_id', $payload)) {
                $supplierId = $payload['supplier_id'] ?: null;
                if ($supplierId) {
                    $supplier = FinanceSupplier::withoutGlobalScopes()
                        ->where('workspace_id', $locked->workspace_id)
                        ->whereKey((int) $supplierId)
                        ->first();
                    if (! $supplier) {
                        throw new RuntimeException('Supplier is invalid for this workspace.');
                    }
                    $supplierId = $supplier->id;
                }
            }

            $categoryId = $locked->category_id;
            if (array_key_exists('category_id', $payload)) {
                $categoryId = $payload['category_id'] ?: null;
                if ($categoryId) {
                    $category = FinanceExpenseCategory::withoutGlobalScopes()
                        ->where('workspace_id', $locked->workspace_id)
                        ->whereKey((int) $categoryId)
                        ->first();
                    if (! $category) {
                        throw new RuntimeException('Expense category is invalid for this workspace.');
                    }
                    $categoryId = $category->id;
                }
            }

            $treasuryId = $locked->treasury_account_id;
            if (array_key_exists('treasury_account_id', $payload)) {
                $treasuryId = $payload['treasury_account_id'] ?: null;
                if ($treasuryId) {
                    $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                        ->where('workspace_id', $locked->workspace_id)
                        ->whereKey((int) $treasuryId)
                        ->first();
                    if (! $treasury) {
                        throw new RuntimeException('Treasury account is invalid for this workspace.');
                    }
                    $treasuryId = $treasury->id;
                }
            }

            $locked->update([
                'supplier_id' => $supplierId,
                'category_id' => $categoryId,
                'treasury_account_id' => $treasuryId,
                'expense_date' => (string) ($payload['expense_date'] ?? $locked->expense_date?->toDateString()),
                'description' => $payload['description'] ?? $locked->description,
                'amount' => $amount,
                'tax_rate' => $taxRate,
                'tax_amount' => $calc['tax_amount'],
                'total' => $calc['total'],
                'currency' => (string) ($payload['currency'] ?? $locked->currency ?: 'SAR'),
                'payment_method' => (string) ($payload['payment_method'] ?? $locked->payment_method),
                'is_recurring' => array_key_exists('is_recurring', $payload)
                    ? (bool) $payload['is_recurring']
                    : (bool) $locked->is_recurring,
                'recurring_frequency' => array_key_exists('recurring_frequency', $payload)
                    ? ($payload['recurring_frequency'] ?: null)
                    : $locked->recurring_frequency,
                'next_due_date' => array_key_exists('next_due_date', $payload)
                    ? ($payload['next_due_date'] ?: null)
                    : $locked->next_due_date,
                'attachment_path' => $attachmentPath,
            ]);

            return $locked->fresh();
        });
    }

    public function delete(FinanceExpense $expense, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($expense, $actorUserId): void {
            $locked = FinanceExpense::withoutGlobalScopes()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'cancelled') {
                return;
            }

            $originalStatus = (string) $locked->status;
            $actorUserId = $actorUserId ?: (int) ($locked->created_by ?: 0);

            if ($originalStatus !== 'draft') {
                $this->reversePostedExpense($locked, $actorUserId, $originalStatus);
            }

            if ($locked->attachment_path) {
                app(SecureUpload::class)->delete($locked->attachment_path);
                $locked->attachment_path = null;
            }

            if ($originalStatus === 'draft') {
                $locked->delete();

                return;
            }

            $locked->update([
                'status' => 'cancelled',
                'attachment_path' => $locked->attachment_path,
            ]);
        });
    }

    private function reversePostedExpense(FinanceExpense $expense, int $actorUserId, string $originalStatus): void
    {
        $this->financialPeriodGuardService->assertDateIsOpen(
            workspaceId: (int) $expense->workspace_id,
            date: now()->toDateString(),
            context: 'عكس مصروف'
        );

        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $expense->workspace_id)
            ->where('type', 'expense')
            ->where('reference_type', FinanceExpense::class)
            ->where('reference_id', $expense->id)
            ->whereNull('reverses_entry_id')
            ->latest('id')
            ->first();

        if ($entry) {
            $alreadyReversed = FinanceJournalEntry::withoutGlobalScopes()
                ->where('workspace_id', $expense->workspace_id)
                ->where('reverses_entry_id', $entry->id)
                ->exists();

            if (! $alreadyReversed && $entry->status !== 'reversed') {
                $this->accountingService->reverseEntry(
                    entry: $entry,
                    type: 'expense_reversal',
                    actorUserId: $actorUserId > 0 ? $actorUserId : (int) ($expense->created_by ?: 0),
                    description: 'عكس قيد المصروف '.$expense->expense_number,
                    referenceType: FinanceExpense::class,
                    referenceId: $expense->id,
                );
            }
        }

        if ($originalStatus === 'paid' && $expense->payment_method !== 'credit' && $expense->treasury_account_id) {
            $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                ->where('workspace_id', $expense->workspace_id)
                ->whereKey($expense->treasury_account_id)
                ->lockForUpdate()
                ->first();
            if ($treasury) {
                $this->treasuryBalanceService->adjust($treasury, (float) $expense->total);
            }
        }
    }

    private function postExpenseEntry(
        FinanceExpense $expense,
        ?FinanceExpenseCategory $category,
        ?FinanceTreasuryAccount $treasury,
        int $actorUserId
    ): void {
        $alreadyPosted = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $expense->workspace_id)
            ->where('type', 'expense')
            ->where('reference_type', FinanceExpense::class)
            ->where('reference_id', $expense->id)
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        $expenseAccount = $category?->linkedAccount ?? $this->chartOfAccountsService->byCode('5900');
        $inputVat = $this->chartOfAccountsService->byCode('1400');
        $ap = $this->chartOfAccountsService->byCode('2000');
        $cash = $this->chartOfAccountsService->byCode('1000');
        $bank = $this->chartOfAccountsService->byCode('1100');

        if (! $expenseAccount || ! $inputVat || ! $ap || ! $cash || ! $bank) {
            throw new RuntimeException('Chart of accounts is incomplete.');
        }

        $creditAccount = $ap;
        if ($expense->status === 'paid' && $expense->payment_method !== 'credit') {
            if ($treasury?->linkedAccount) {
                $creditAccount = $treasury->linkedAccount;
            } else {
                $creditAccount = $treasury?->type === 'bank' ? $bank : $cash;
            }
        }

        $lines = [
            [
                'account_id' => $expenseAccount->id,
                'debit' => (float) $expense->amount,
                'credit' => 0,
                'description' => 'Expense amount',
                'entity_type' => FinanceExpense::class,
                'entity_id' => $expense->id,
            ],
        ];

        if ((float) $expense->tax_amount > 0) {
            $lines[] = [
                'account_id' => $inputVat->id,
                'debit' => (float) $expense->tax_amount,
                'credit' => 0,
                'description' => 'Input VAT on expense',
                'entity_type' => FinanceExpense::class,
                'entity_id' => $expense->id,
            ];
        }

        $lines[] = [
            'account_id' => $creditAccount->id,
            'debit' => 0,
            'credit' => (float) $expense->total,
            'description' => $creditAccount->id === $ap->id ? 'Accounts payable for expense' : 'Cash/Bank expense payment',
            'entity_type' => FinanceExpense::class,
            'entity_id' => $expense->id,
        ];

        $this->accountingService->createEntry(
            workspaceId: (int) $expense->workspace_id,
            entryDate: $expense->expense_date?->toDateString() ?? now()->toDateString(),
            type: 'expense',
            lines: $lines,
            description: 'Expense '.$expense->expense_number,
            referenceType: FinanceExpense::class,
            referenceId: $expense->id,
            postedBy: $actorUserId
        );

        if ($creditAccount->code !== '2000' && $treasury) {
            $this->treasuryBalanceService->adjust($treasury, -1 * (float) $expense->total);
        }
    }

    private function nextExpenseNumber(int $workspaceId): string
    {
        $prefix = 'EXP-'.date('Y').'-';
        $last = FinanceExpense::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('expense_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $counter = $last ? ((int) substr($last->expense_number, -6) + 1) : 1;

        return $prefix.str_pad((string) $counter, 6, '0', STR_PAD_LEFT);
    }
}
