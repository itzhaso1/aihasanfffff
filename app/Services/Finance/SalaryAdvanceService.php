<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Finance\FinanceSalaryAdvanceRepayment;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalaryAdvanceService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
        private readonly TreasuryBalanceService $treasuryBalanceService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function issue(Workspace $workspace, array $payload, int $actorUserId): FinanceSalaryAdvance
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('قيمة السلفة يجب أن تكون أكبر من صفر.');
        }

        $financeEmployee = FinanceEmployee::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey((int) ($payload['finance_employee_id'] ?? 0))
            ->where('status', 'active')
            ->first();
        if (! $financeEmployee) {
            throw new RuntimeException('الموظف المحدد غير موجود ضمن موظفي الشركة في قسم الفوترة.');
        }

        $legacyUserId = $this->resolveLegacyWorkspaceUserId(
            workspaceId: $workspace->id,
            requestedUserId: (int) ($payload['user_id'] ?? 0),
            actorUserId: $actorUserId
        );

        [$treasuryAccount, $treasuryFinanceAccount] = $this->resolveTreasuryAccount(
            treasuryAccountId: (int) ($payload['treasury_account_id'] ?? 0),
            workspaceId: $workspace->id
        );
        $employeeAdvances = $this->chartOfAccountsService->byCode('1210');
        if (! $employeeAdvances || ! $treasuryFinanceAccount) {
            throw new RuntimeException('حسابات السلف غير مكتملة في دليل الحسابات.');
        }

        return DB::transaction(function () use ($workspace, $payload, $actorUserId, $amount, $treasuryAccount, $treasuryFinanceAccount, $employeeAdvances, $financeEmployee, $legacyUserId): FinanceSalaryAdvance {
            $advance = FinanceSalaryAdvance::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'finance_employee_id' => $financeEmployee->id,
                'user_id' => $legacyUserId,
                'amount' => $amount,
                'remaining_amount' => $amount,
                'issued_at' => $payload['issued_at'],
                'status' => 'open',
                'type' => $payload['type'] ?? 'salary_advance',
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'notes' => $payload['notes'] ?? null,
            ]);

            $issuedDate = $advance->issued_at?->toDateString() ?? now()->toDateString();
            $this->financialPeriodGuardService->assertDateIsOpen(
                workspaceId: $workspace->id,
                date: $issuedDate,
                context: 'إصدار سلفة موظف'
            );

            $entry = FinanceJournalEntry::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('type', 'payroll')
                ->where('reference_type', FinanceSalaryAdvance::class)
                ->where('reference_id', $advance->id)
                ->first();

            if (! $entry) {
                $entry = $this->accountingService->createEntry(
                    workspaceId: $workspace->id,
                    entryDate: $issuedDate,
                    type: 'payroll',
                    lines: [
                        [
                            'account_id' => $employeeAdvances->id,
                            'debit' => $amount,
                            'credit' => 0,
                            'description' => 'Employee advance issued',
                            'entity_type' => FinanceSalaryAdvance::class,
                            'entity_id' => $advance->id,
                        ],
                        [
                            'account_id' => $treasuryFinanceAccount->id,
                            'debit' => 0,
                            'credit' => $amount,
                            'description' => 'Cash/Bank advance payout',
                            'entity_type' => FinanceSalaryAdvance::class,
                            'entity_id' => $advance->id,
                        ],
                    ],
                    description: 'Salary advance issued',
                    referenceType: FinanceSalaryAdvance::class,
                    referenceId: $advance->id,
                    postedBy: $actorUserId
                );
            }

            $advance->update([
                'notes' => trim((string) ($advance->notes ?: '')).($entry->id ? "\nJE#{$entry->entry_number}" : ''),
            ]);

            $this->treasuryBalanceService->adjust($treasuryAccount, -1 * $amount);

            return $advance->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordRepayment(FinanceSalaryAdvance $advance, array $payload, int $actorUserId): FinanceSalaryAdvanceRepayment
    {
        if ($advance->status !== 'open') {
            throw new RuntimeException('السلفة مغلقة ولا يمكن تسجيل سداد جديد.');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('قيمة السداد يجب أن تكون أكبر من صفر.');
        }

        $remaining = round((float) $advance->remaining_amount, 2);
        if ($amount > $remaining) {
            throw new RuntimeException('قيمة السداد أكبر من المتبقي على السلفة.');
        }

        $method = (string) ($payload['method'] ?? 'cash');
        $employeeAdvances = $this->chartOfAccountsService->byCode('1210');
        $payrollPayable = $this->chartOfAccountsService->byCode('2200') ?? $this->chartOfAccountsService->byCode('2000');
        if (! $employeeAdvances) {
            throw new RuntimeException('حساب سلف الموظفين غير متوفر.');
        }

        [$treasuryAccount, $treasuryFinanceAccount] = $method === 'payroll_deduction'
            ? [null, null]
            : $this->resolveTreasuryAccount(
                treasuryAccountId: (int) ($payload['treasury_account_id'] ?? 0),
                workspaceId: (int) $advance->workspace_id
            );

        return DB::transaction(function () use (
            $advance,
            $payload,
            $actorUserId,
            $amount,
            $method,
            $employeeAdvances,
            $payrollPayable,
            $treasuryAccount,
            $treasuryFinanceAccount
        ): FinanceSalaryAdvanceRepayment {
            $paymentDate = (string) ($payload['payment_date'] ?? now()->toDateString());
            $this->financialPeriodGuardService->assertDateIsOpen(
                workspaceId: (int) $advance->workspace_id,
                date: $paymentDate,
                context: 'تسجيل سداد سلفة'
            );

            $repayment = FinanceSalaryAdvanceRepayment::withoutGlobalScopes()->create([
                'workspace_id' => $advance->workspace_id,
                'salary_advance_id' => $advance->id,
                'treasury_account_id' => $treasuryAccount?->id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'method' => $method,
                'status' => 'posted',
                'notes' => $payload['notes'] ?? null,
                'posted_journal_entry_id' => null,
                'created_by' => $actorUserId,
            ]);

            $entryLines = [];
            if ($method === 'payroll_deduction') {
                if (! $payrollPayable) {
                    throw new RuntimeException('حساب التزامات الرواتب غير متوفر.');
                }
                $entryLines = [
                    [
                        'account_id' => $payrollPayable->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'Advance repayment through payroll deduction',
                        'entity_type' => FinanceSalaryAdvanceRepayment::class,
                        'entity_id' => $repayment->id,
                    ],
                    [
                        'account_id' => $employeeAdvances->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Reduce employee advances receivable',
                        'entity_type' => FinanceSalaryAdvanceRepayment::class,
                        'entity_id' => $repayment->id,
                    ],
                ];
            } else {
                if (! $treasuryFinanceAccount || ! $treasuryAccount) {
                    throw new RuntimeException('حساب الخزينة غير متاح لتسجيل السداد.');
                }
                $entryLines = [
                    [
                        'account_id' => $treasuryFinanceAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'Cash/Bank received against advance',
                        'entity_type' => FinanceSalaryAdvanceRepayment::class,
                        'entity_id' => $repayment->id,
                    ],
                    [
                        'account_id' => $employeeAdvances->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Reduce employee advances receivable',
                        'entity_type' => FinanceSalaryAdvanceRepayment::class,
                        'entity_id' => $repayment->id,
                    ],
                ];
            }

            $entry = $this->accountingService->createEntry(
                workspaceId: (int) $advance->workspace_id,
                entryDate: $paymentDate,
                type: 'payroll',
                lines: $entryLines,
                description: 'Repayment for employee advance #'.$advance->id,
                referenceType: FinanceSalaryAdvanceRepayment::class,
                referenceId: $repayment->id,
                postedBy: $actorUserId
            );

            $repayment->update([
                'status' => 'posted',
                'posted_journal_entry_id' => $entry->id,
            ]);

            $remaining = round((float) $advance->remaining_amount - $amount, 2);
            $advance->update([
                'remaining_amount' => $remaining,
                'status' => $remaining <= 0.009 ? 'closed' : 'open',
            ]);

            if ($treasuryAccount) {
                $this->treasuryBalanceService->adjust($treasuryAccount, $amount);
            }

            return $repayment;
        });
    }

    /**
     * @return array{0:FinanceTreasuryAccount,1:FinanceAccount}
     */
    private function resolveTreasuryAccount(int $treasuryAccountId, int $workspaceId): array
    {
        $treasury = null;
        if ($treasuryAccountId > 0) {
            $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereKey($treasuryAccountId)
                ->first();
        }

        if (! $treasury) {
            $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderByRaw("CASE WHEN type = 'cash' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();
        }

        if (! $treasury) {
            throw new RuntimeException('لا يوجد حساب خزينة نشط.');
        }

        $financeAccount = $treasury->linkedAccount;
        if (! $financeAccount) {
            $fallbackCode = $treasury->type === 'bank' ? '1100' : '1000';
            $financeAccount = $this->chartOfAccountsService->byCode($fallbackCode);
        }

        if (! $financeAccount) {
            throw new RuntimeException('لا يوجد حساب محاسبي مرتبط بالخزينة.');
        }

        return [$treasury, $financeAccount];
    }

    private function resolveLegacyWorkspaceUserId(int $workspaceId, int $requestedUserId, int $actorUserId): int
    {
        if ($requestedUserId > 0) {
            $requestedExists = WorkspaceUser::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $requestedUserId)
                ->where('status', 'active')
                ->exists();
            if ($requestedExists) {
                return $requestedUserId;
            }
        }

        if ($actorUserId > 0) {
            $actorExists = WorkspaceUser::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $actorUserId)
                ->where('status', 'active')
                ->exists();
            if ($actorExists) {
                return $actorUserId;
            }
        }

        $fallbackUserId = WorkspaceUser::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('user_id');

        if (! $fallbackUserId) {
            throw new RuntimeException('لا يوجد مستخدم نشط في مساحة العمل لحفظ السجل المحاسبي.');
        }

        return (int) $fallbackUserId;
    }
}
