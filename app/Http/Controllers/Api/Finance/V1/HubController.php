<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\BillingDashboardService;
use App\Services\Finance\BusinessAlertService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\SalesService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HubController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
        private readonly SalesService $salesService,
        private readonly BillingDashboardService $billingDashboardService,
        private readonly BusinessAlertService $businessAlertService,
    ) {}

    public function sales(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $filters = $request->only(['from', 'to', 'customer_id', 'status']);
        $summary = $this->salesService->summary($filters);
        $invoices = $this->salesService->paginateInvoices($filters, 10);

        return $this->ok([
            'summary' => [
                'invoice_count' => $summary['invoice_count'],
                'total_sales' => $this->presenter->money($summary['total_sales']),
                'total_due' => $this->presenter->money($summary['total_due']),
                'total_paid' => $this->presenter->money($summary['total_paid']),
                'overdue_count' => $summary['overdue_count'],
                'unpaid_count' => $summary['unpaid_count'],
            ],
            'invoices' => $invoices->getCollection()
                ->map(fn (FinanceInvoice $invoice) => $this->presenter->invoiceSummary($invoice))
                ->values()
                ->all(),
            'recent_payments' => $this->salesService->recentPayments(10)
                ->map(fn (FinanceInvoicePayment $payment) => $this->presenter->payment($payment))
                ->values()
                ->all(),
        ], meta: $this->pageMeta($invoices));
    }

    public function billing(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'invoices.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $metrics = $this->billingDashboardService->metrics($request->only(['from', 'to', 'type', 'customer_id', 'currency']));

        return $this->ok([
            'total_invoices' => $metrics['total_invoices'],
            'draft' => $metrics['draft'],
            'issued' => $metrics['issued'],
            'paid' => $metrics['paid'],
            'partial' => $metrics['partial'],
            'overdue' => $metrics['overdue'],
            'cancelled' => $metrics['cancelled'],
            'unpaid' => $metrics['unpaid'],
            'upcoming_due' => $metrics['upcoming_due'],
            'due_today' => $metrics['due_today'],
            'total_revenue' => $this->presenter->money($metrics['total_revenue']),
            'outstanding_amount' => $this->presenter->money($metrics['outstanding_amount']),
            'overdue_amount' => $this->presenter->money($metrics['overdue_amount']),
            'payments_received' => $this->presenter->money($metrics['payments_received']),
            'credits_issued' => $this->presenter->money($metrics['credits_issued']),
        ]);
    }

    public function vat(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'accounting.view');

        $output = FinanceInvoice::query()->where('type', 'sales')->sum('tax_amount');
        $inputFromPurchases = FinanceInvoice::query()->where('type', 'purchase')->sum('tax_amount');
        $inputFromExpenses = FinanceExpense::query()->sum('tax_amount');
        $input = (float) $inputFromPurchases + (float) $inputFromExpenses;

        return $this->ok([
            'output' => $this->presenter->money($output),
            'input' => $this->presenter->money($input),
            'net' => $this->presenter->money((float) $output - $input),
            'rates' => FinanceTaxRate::query()
                ->orderByDesc('is_default')
                ->get()
                ->map(fn (FinanceTaxRate $rate) => $this->presenter->taxRate($rate))
                ->values()
                ->all(),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        return $this->ok($this->businessAlertService->alerts((int) $workspace->id));
    }

    public function accounting(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'accounting.view');

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

        $monthExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', payment_date)"
            : "DATE_FORMAT(payment_date, '%Y-%m')";

        $monthlyCashFlow = DB::table('finance_invoice_payments')
            ->selectRaw($monthExpr.' as month, SUM(amount) as inflow')
            ->where('workspace_id', $workspace->id)
            ->whereDate('payment_date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'inflow' => Money::of($row->inflow ?? 0),
            ])
            ->all();

        return $this->ok([
            'accounts' => $accounts->getCollection()
                ->map(fn (FinanceAccount $account) => $this->presenter->ledgerAccount($account))
                ->values()
                ->all(),
            'entries' => $entries->getCollection()
                ->map(fn (FinanceJournalEntry $entry) => $this->presenter->journalEntry($entry))
                ->values()
                ->all(),
            'trial_balance' => $trialBalance->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'debit_total' => $this->presenter->money($row->debit_total ?? 0),
                'credit_total' => $this->presenter->money($row->credit_total ?? 0),
            ])->values()->all(),
            'trial_totals' => [
                'debit' => $this->presenter->money($trialBalance->sum('debit_total')),
                'credit' => $this->presenter->money($trialBalance->sum('credit_total')),
            ],
            'monthly_cash_flow' => $monthlyCashFlow,
        ], meta: [
            'accounts' => $this->pageMeta($accounts),
            'entries' => $this->pageMeta($entries),
        ]);
    }

    public function banks(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = FinanceTreasuryAccount::query()
            ->orderBy('type')
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (FinanceTreasuryAccount $account) => $this->presenter->treasuryAccount($account))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }
}
