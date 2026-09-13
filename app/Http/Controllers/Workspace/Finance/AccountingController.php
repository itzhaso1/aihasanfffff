<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountingController extends FinanceBaseController
{
    public function dashboard(Request $request): View
    {
        $this->authorizeFinance($request, 'accounting.view');
        $workspace = $this->currentWorkspace();

        $accounts = FinanceAccount::query()
            ->withSum('lines as debit_total', 'debit')
            ->withSum('lines as credit_total', 'credit')
            ->orderBy('code')
            ->paginate(30);

        $entries = FinanceJournalEntry::query()
            ->with('lines.account')
            ->latest('id')
            ->paginate(20);

        $trialBalance = FinanceAccount::query()
            ->select('finance_accounts.id', 'finance_accounts.code', 'finance_accounts.name', 'finance_accounts.type')
            ->leftJoin('finance_journal_entry_lines', function ($join): void {
                $join->on('finance_journal_entry_lines.account_id', '=', 'finance_accounts.id')
                    ->on('finance_journal_entry_lines.workspace_id', '=', 'finance_accounts.workspace_id');
            })
            ->groupBy('finance_accounts.id', 'finance_accounts.code', 'finance_accounts.name', 'finance_accounts.type')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.debit),0) as debit_total')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.credit),0) as credit_total')
            ->orderBy('finance_accounts.code')
            ->get();

        $trialTotals = [
            'debit' => round((float) $trialBalance->sum('debit_total'), 2),
            'credit' => round((float) $trialBalance->sum('credit_total'), 2),
        ];

        $monthExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', payment_date)"
            : "DATE_FORMAT(payment_date, '%Y-%m')";

        $monthlyCashFlow = DB::table('finance_invoice_payments')
            ->selectRaw($monthExpr.' as month, SUM(amount) as inflow')
            ->where('workspace_id', $workspace->id)
            ->whereDate('payment_date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return view('workspace.finance.accounting.dashboard', [
            'accounts' => $accounts,
            'entries' => $entries,
            'trialBalance' => $trialBalance,
            'trialTotals' => $trialTotals,
            'monthlyCashFlow' => $monthlyCashFlow,
        ]);
    }
}
