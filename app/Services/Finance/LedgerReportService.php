<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntryLine;
use App\Models\Product;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LedgerReportService
{
    /**
     * @return array<string, mixed>
     */
    public function trialBalance(int $workspaceId, string $asOf): array
    {
        $rows = $this->accountBalances($workspaceId, null, $asOf);
        $debit = '0.00';
        $credit = '0.00';
        foreach ($rows as $row) {
            $debit = Money::add($debit, $row['debit']);
            $credit = Money::add($credit, $row['credit']);
        }

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balanced' => Money::cmp($debit, $credit) === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profitAndLoss(int $workspaceId, string $from, string $to): array
    {
        $rows = $this->accountBalances($workspaceId, $from, $to, ['revenue', 'expense']);
        $revenue = '0.00';
        $cogs = '0.00';
        $operatingExpense = '0.00';
        $otherExpense = '0.00';

        foreach ($rows as $row) {
            $net = $this->naturalBalance($row['type'], $row['debit'], $row['credit']);
            if ($row['type'] === 'revenue') {
                $revenue = Money::add($revenue, $net);
            } elseif ($row['classification'] === 'cogs') {
                $cogs = Money::add($cogs, $net);
            } elseif ($row['classification'] === 'other_expense') {
                $otherExpense = Money::add($otherExpense, $net);
            } else {
                $operatingExpense = Money::add($operatingExpense, $net);
            }
        }

        $grossProfit = Money::sub($revenue, $cogs);
        $netProfit = Money::sub(Money::sub($grossProfit, $operatingExpense), $otherExpense);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpense,
            'other_expenses' => $otherExpense,
            'net_profit' => $netProfit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(int $workspaceId, string $asOf): array
    {
        $rows = $this->accountBalances($workspaceId, null, $asOf, ['asset', 'liability', 'equity']);
        $income = $this->profitAndLoss($workspaceId, '1970-01-01', $asOf);

        $assets = '0.00';
        $liabilities = '0.00';
        $equity = '0.00';
        foreach ($rows as &$row) {
            $row['balance'] = $this->naturalBalance($row['type'], $row['debit'], $row['credit']);
            if ($row['type'] === 'asset') {
                $assets = Money::add($assets, $row['balance']);
            } elseif ($row['type'] === 'liability') {
                $liabilities = Money::add($liabilities, $row['balance']);
            } else {
                $equity = Money::add($equity, $row['balance']);
            }
        }
        unset($row);

        $currentEarnings = $income['net_profit'];
        $equity = Money::add($equity, $currentEarnings);
        $totalLiabilitiesAndEquity = Money::add($liabilities, $equity);

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_year_earnings' => $currentEarnings,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'balanced' => Money::cmp($assets, $totalLiabilitiesAndEquity) === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generalLedger(int $workspaceId, ?int $accountId, string $from, string $to): array
    {
        $query = FinanceJournalEntryLine::withoutGlobalScopes()
            ->select('finance_journal_entry_lines.*')
            ->join('finance_journal_entries', 'finance_journal_entries.id', '=', 'finance_journal_entry_lines.journal_entry_id')
            ->where('finance_journal_entry_lines.workspace_id', $workspaceId)
            ->whereIn('finance_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('finance_journal_entries.entry_date', '>=', $from)
            ->whereDate('finance_journal_entries.entry_date', '<=', $to)
            ->with(['account', 'journalEntry'])
            ->orderBy('finance_journal_entries.entry_date')
            ->orderBy('finance_journal_entry_lines.id');

        if ($accountId) {
            $query->where('finance_journal_entry_lines.account_id', $accountId);
        }

        $lines = $query->get();
        $running = '0.00';
        $mapped = [];
        foreach ($lines as $line) {
            $running = Money::add($running, Money::sub($line->debit, $line->credit));
            $mapped[] = [
                'date' => $line->journalEntry?->entry_date?->toDateString(),
                'entry_number' => $line->journalEntry?->entry_number,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'description' => $line->description ?: $line->journalEntry?->description,
                'debit' => Money::of($line->debit),
                'credit' => Money::of($line->credit),
                'balance' => $running,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'account_id' => $accountId,
            'lines' => $mapped,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aging(int $workspaceId, string $type, string $asOf): array
    {
        $invoices = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', $type)
            ->whereIssued()
            ->where('amount_due', '>', 0)
            ->orderBy('due_date')
            ->get();

        $buckets = [
            'current' => '0.00',
            '1_30' => '0.00',
            '31_60' => '0.00',
            '61_90' => '0.00',
            '90_plus' => '0.00',
        ];
        $rows = [];
        $asOfDate = Carbon::parse($asOf)->startOfDay();

        foreach ($invoices as $invoice) {
            $due = $invoice->due_date ?: $invoice->issue_date;
            $days = $due ? $due->startOfDay()->diffInDays($asOfDate, false) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $amount = Money::of($invoice->amount_due);
            $buckets[$bucket] = Money::add($buckets[$bucket], $amount);
            $rows[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_date' => $invoice->due_date?->toDateString(),
                'days_overdue' => max(0, (int) $days),
                'amount_due' => $amount,
                'bucket' => $bucket,
            ];
        }

        return [
            'type' => $type,
            'as_of' => $asOf,
            'buckets' => $buckets,
            'total' => array_reduce($buckets, fn (string $carry, string $value): string => Money::add($carry, $value), '0.00'),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Natural signed balance of one chart account as of $asOf (inclusive entry_date).
     * Assets/expenses: net debit. Liabilities/equity/revenue: net credit.
     */
    public function accountNaturalBalance(int $workspaceId, string $code, string $asOf): string
    {
        $account = FinanceAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('code', $code)
            ->first();

        if ($account === null) {
            return '0.00';
        }

        $netDebit = $this->netDebitBalance($workspaceId, [(int) $account->id], null, $asOf);

        return $this->naturalBalance($account->type, $netDebit, '0');
    }

    public function cashFlow(int $workspaceId, string $from, string $to): array
    {
        $cashAccounts = FinanceAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('code', ['1000', '1100'])
            ->pluck('id');

        $opening = $this->netDebitBalance($workspaceId, $cashAccounts->all(), null, Carbon::parse($from)->subDay()->toDateString());
        $closing = $this->netDebitBalance($workspaceId, $cashAccounts->all(), null, $to);
        $period = $this->netDebitBalance($workspaceId, $cashAccounts->all(), $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'opening_cash' => $opening,
            'net_change' => $period,
            'closing_cash' => $closing,
            'balanced' => Money::cmp(Money::add($opening, $period), $closing) === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryValuation(int $workspaceId): array
    {
        $products = Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('inventory_tracking', true)
            ->orderBy('name')
            ->get();

        $rows = [];
        $total = '0.00';
        foreach ($products as $product) {
            $value = Money::mul($product->cost_price ?: 0, (int) $product->stock);
            $total = Money::add($total, $value);
            $rows[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => (int) $product->stock,
                'cost' => Money::of($product->cost_price ?: 0),
                'value' => $value,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /**
     * @param  array<int, string>|null  $types
     * @return array<int, array<string, mixed>>
     */
    private function accountBalances(int $workspaceId, ?string $from, string $to, ?array $types = null): array
    {
        $totals = FinanceJournalEntryLine::withoutGlobalScopes()
            ->select('finance_journal_entry_lines.account_id')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.debit),0) as debit_total')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.credit),0) as credit_total')
            ->join('finance_journal_entries', 'finance_journal_entries.id', '=', 'finance_journal_entry_lines.journal_entry_id')
            ->where('finance_journal_entry_lines.workspace_id', $workspaceId)
            ->whereIn('finance_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('finance_journal_entries.entry_date', '<=', $to)
            ->when($from, fn ($query) => $query->whereDate('finance_journal_entries.entry_date', '>=', $from))
            ->groupBy('finance_journal_entry_lines.account_id');

        $query = FinanceAccount::withoutGlobalScopes()
            ->select(
                'finance_accounts.id',
                'finance_accounts.code',
                'finance_accounts.name',
                'finance_accounts.type',
                'finance_accounts.classification'
            )
            ->leftJoinSub($totals, 'line_totals', function ($join): void {
                $join->on('line_totals.account_id', '=', 'finance_accounts.id');
            })
            ->addSelect(DB::raw('COALESCE(line_totals.debit_total,0) as debit_total'))
            ->addSelect(DB::raw('COALESCE(line_totals.credit_total,0) as credit_total'))
            ->where('finance_accounts.workspace_id', $workspaceId)
            ->orderBy('finance_accounts.code');

        if ($types) {
            $query->whereIn('finance_accounts.type', $types);
        }

        return $query->get()->map(function ($row): array {
            return [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'classification' => $row->classification,
                'debit' => Money::of($row->debit_total),
                'credit' => Money::of($row->credit_total),
                'balance' => $this->naturalBalance($row->type, $row->debit_total, $row->credit_total),
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $accountIds
     */
    private function netDebitBalance(int $workspaceId, array $accountIds, ?string $from, string $to): string
    {
        if ($accountIds === []) {
            return '0.00';
        }

        $row = FinanceJournalEntryLine::withoutGlobalScopes()
            ->join('finance_journal_entries', 'finance_journal_entries.id', '=', 'finance_journal_entry_lines.journal_entry_id')
            ->where('finance_journal_entry_lines.workspace_id', $workspaceId)
            ->whereIn('finance_journal_entry_lines.account_id', $accountIds)
            ->whereIn('finance_journal_entries.status', ['posted', 'reversed'])
            ->whereDate('finance_journal_entries.entry_date', '<=', $to)
            ->when($from, fn ($query) => $query->whereDate('finance_journal_entries.entry_date', '>=', $from))
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.debit),0) as debit_total')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.credit),0) as credit_total')
            ->first();

        return Money::sub($row?->debit_total ?? 0, $row?->credit_total ?? 0);
    }

    private function naturalBalance(string $type, int|float|string $debit, int|float|string $credit): string
    {
        $netDebit = Money::sub($debit, $credit);

        return in_array($type, ['liability', 'equity', 'revenue'], true)
            ? Money::sub('0', $netDebit)
            : $netDebit;
    }
}
