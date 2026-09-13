<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TreasuryTransferService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
        private readonly TreasuryBalanceService $treasuryBalanceService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transfer(int $workspaceId, array $payload, int $actorUserId): FinanceTreasuryTransfer
    {
        $amount = Money::of($payload['amount'] ?? 0);
        if (! Money::isPositive($amount)) {
            throw new RuntimeException('Transfer amount must be greater than zero.');
        }

        $fromId = (int) ($payload['from_treasury_account_id'] ?? 0);
        $toId = (int) ($payload['to_treasury_account_id'] ?? 0);
        if ($fromId === $toId) {
            throw new RuntimeException('Source and destination treasury accounts must be different.');
        }

        $reference = trim((string) ($payload['reference'] ?? ''));
        $transferDate = (string) ($payload['transfer_date'] ?? now()->toDateString());

        $this->financialPeriodGuardService->assertDateIsOpen(
            workspaceId: $workspaceId,
            date: $transferDate,
            context: 'تحويل خزينة'
        );

        return DB::transaction(function () use ($workspaceId, $amount, $fromId, $toId, $reference, $transferDate, $payload, $actorUserId): FinanceTreasuryTransfer {
            if ($reference !== '') {
                $existing = FinanceTreasuryTransfer::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('reference', $reference)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $from = FinanceTreasuryAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereKey($fromId)
                ->lockForUpdate()
                ->first();
            $to = FinanceTreasuryAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereKey($toId)
                ->lockForUpdate()
                ->first();

            if (! $from || ! $to) {
                throw new RuntimeException('Treasury accounts are invalid for this workspace.');
            }

            if (! $from->linked_finance_account_id || ! $to->linked_finance_account_id) {
                throw new RuntimeException('Treasury accounts must be linked to ledger accounts.');
            }

            try {
                $transfer = FinanceTreasuryTransfer::withoutGlobalScopes()->create([
                    'workspace_id' => $workspaceId,
                    'from_treasury_account_id' => $from->id,
                    'to_treasury_account_id' => $to->id,
                    'amount' => $amount,
                    'transfer_date' => $transferDate,
                    'reference' => $reference !== '' ? $reference : null,
                    'status' => 'posted',
                    'notes' => $payload['notes'] ?? null,
                    'created_by' => $actorUserId,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($reference === '') {
                    throw $exception;
                }

                $existing = FinanceTreasuryTransfer::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('reference', $reference)
                    ->first();
                if ($existing) {
                    return $existing;
                }

                throw $exception;
            }

            $entry = $this->accountingService->createEntry(
                workspaceId: $workspaceId,
                entryDate: $transferDate,
                type: 'treasury_transfer',
                lines: [
                    [
                        'account_id' => (int) $to->linked_finance_account_id,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'Treasury transfer in',
                        'entity_type' => FinanceTreasuryTransfer::class,
                        'entity_id' => $transfer->id,
                    ],
                    [
                        'account_id' => (int) $from->linked_finance_account_id,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Treasury transfer out',
                        'entity_type' => FinanceTreasuryTransfer::class,
                        'entity_id' => $transfer->id,
                    ],
                ],
                description: 'Treasury transfer '.$from->name.' → '.$to->name,
                referenceType: FinanceTreasuryTransfer::class,
                referenceId: $transfer->id,
                postedBy: $actorUserId,
            );

            $this->treasuryBalanceService->adjust($from, -1 * Money::round($amount));
            $this->treasuryBalanceService->adjust($to, Money::round($amount));

            $transfer->update(['journal_entry_id' => $entry->id]);

            return $transfer->fresh();
        });
    }
}
