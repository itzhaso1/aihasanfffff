<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceBankStatement;
use App\Models\Finance\FinanceBankStatementLine;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BankReconciliationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createStatement(int $workspaceId, array $payload): FinanceBankStatement
    {
        return FinanceBankStatement::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'treasury_account_id' => (int) $payload['treasury_account_id'],
            'statement_date' => $payload['statement_date'],
            'opening_balance' => Money::of($payload['opening_balance'] ?? 0),
            'closing_balance' => Money::of($payload['closing_balance'] ?? 0),
            'status' => 'open',
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function addLines(FinanceBankStatement $statement, array $lines): void
    {
        if ($statement->status === 'reconciled') {
            throw new RuntimeException('Cannot add lines to a reconciled statement.');
        }

        foreach ($lines as $line) {
            $amount = Money::of($line['amount'] ?? 0);
            if (Money::isZero($amount)) {
                continue;
            }

            FinanceBankStatementLine::withoutGlobalScopes()->create([
                'workspace_id' => $statement->workspace_id,
                'bank_statement_id' => $statement->id,
                'posted_date' => $line['posted_date'] ?? $statement->statement_date,
                'description' => $line['description'] ?? null,
                'reference' => $line['reference'] ?? null,
                'amount' => $amount,
                'status' => 'unmatched',
            ]);
        }
    }

    public function suggestMatches(FinanceBankStatement $statement): int
    {
        $suggested = 0;
        $lines = FinanceBankStatementLine::withoutGlobalScopes()
            ->where('bank_statement_id', $statement->id)
            ->where('status', 'unmatched')
            ->get();

        foreach ($lines as $line) {
            $suggestion = $this->bestSuggestion($statement, $line);
            if (! $suggestion) {
                continue;
            }

            $line->update([
                'status' => 'suggested',
                'suggested_type' => $suggestion['type'],
                'suggested_id' => $suggestion['id'],
                'suggestion_confidence' => $suggestion['confidence'],
                'suggestion_reason' => $suggestion['reason'],
            ]);
            $suggested++;
        }

        return $suggested;
    }

    public function matchLine(FinanceBankStatementLine $line, string $type, int $id, int $actorUserId): FinanceBankStatementLine
    {
        if (! in_array($line->status, ['unmatched', 'suggested'], true)) {
            throw new RuntimeException('Line is already matched.');
        }

        $this->assertMatchTarget((int) $line->workspace_id, $type, $id);

        $line->update([
            'status' => 'matched',
            'matched_type' => $type,
            'matched_id' => $id,
            'matched_by' => $actorUserId,
            'matched_at' => now(),
        ]);

        return $line->fresh();
    }

    public function ignoreLine(FinanceBankStatementLine $line): FinanceBankStatementLine
    {
        if ($line->status === 'matched') {
            throw new RuntimeException('Cannot ignore a matched line.');
        }

        $line->update(['status' => 'ignored']);

        return $line->fresh();
    }

    public function complete(FinanceBankStatement $statement): FinanceBankStatement
    {
        return DB::transaction(function () use ($statement): FinanceBankStatement {
            $locked = FinanceBankStatement::withoutGlobalScopes()->whereKey($statement->id)->lockForUpdate()->firstOrFail();
            $open = FinanceBankStatementLine::withoutGlobalScopes()
                ->where('bank_statement_id', $locked->id)
                ->whereIn('status', ['unmatched', 'suggested'])
                ->exists();
            if ($open) {
                throw new RuntimeException('All statement lines must be matched or ignored before completing reconciliation.');
            }

            $locked->update(['status' => 'reconciled', 'reconciled_at' => now()]);

            return $locked->fresh();
        });
    }

    /**
     * @return array{type:string,id:int,confidence:int,reason:string}|null
     */
    private function bestSuggestion(FinanceBankStatement $statement, FinanceBankStatementLine $line): ?array
    {
        $amount = Money::of($line->amount);
        $workspaceId = (int) $statement->workspace_id;
        $date = (string) ($line->posted_date?->toDateString() ?? $statement->statement_date?->toDateString());

        $payment = FinanceInvoicePayment::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('treasury_account_id', $statement->treasury_account_id)
            ->where('amount', $amount)
            ->whereDate('payment_date', $date)
            ->first();
        if ($payment) {
            return [
                'type' => FinanceInvoicePayment::class,
                'id' => (int) $payment->id,
                'confidence' => 90,
                'reason' => 'Matching payment amount and date',
            ];
        }

        $expense = FinanceExpense::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('treasury_account_id', $statement->treasury_account_id)
            ->where('total', $amount)
            ->whereDate('expense_date', $date)
            ->first();
        if ($expense) {
            return [
                'type' => FinanceExpense::class,
                'id' => (int) $expense->id,
                'confidence' => 80,
                'reason' => 'Matching expense amount and date',
            ];
        }

        $transfer = FinanceTreasuryTransfer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($statement): void {
                $query->where('from_treasury_account_id', $statement->treasury_account_id)
                    ->orWhere('to_treasury_account_id', $statement->treasury_account_id);
            })
            ->where('amount', $amount)
            ->whereDate('transfer_date', $date)
            ->first();
        if ($transfer) {
            return [
                'type' => FinanceTreasuryTransfer::class,
                'id' => (int) $transfer->id,
                'confidence' => 85,
                'reason' => 'Matching treasury transfer amount and date',
            ];
        }

        return null;
    }

    private function assertMatchTarget(int $workspaceId, string $type, int $id): void
    {
        $exists = match ($type) {
            FinanceInvoicePayment::class => FinanceInvoicePayment::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)->whereKey($id)->exists(),
            FinanceExpense::class => FinanceExpense::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)->whereKey($id)->exists(),
            FinanceTreasuryTransfer::class => FinanceTreasuryTransfer::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)->whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new RuntimeException('Match target is invalid for this workspace.');
        }
    }
}
