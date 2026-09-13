<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceBankStatement;
use App\Models\Finance\FinanceBankStatementLine;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Services\Finance\BankReconciliationService;
use App\Services\Finance\TreasuryTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class TreasuryController extends FinanceBaseController
{
    public function __construct(
        private readonly TreasuryTransferService $treasuryTransferService,
        private readonly BankReconciliationService $bankReconciliationService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        return view('workspace.finance.treasury.index', [
            'accounts' => FinanceTreasuryAccount::query()->with('linkedAccount')->orderBy('type')->orderBy('name')->get(),
            'transfers' => FinanceTreasuryTransfer::query()
                ->with(['fromAccount', 'toAccount'])
                ->latest('id')
                ->limit(20)
                ->get(),
            'statements' => FinanceBankStatement::query()
                ->with('treasuryAccount')
                ->latest('id')
                ->paginate(10),
        ]);
    }

    public function transfer(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'accounting.manage');
        $validated = $request->validate([
            'from_treasury_account_id' => ['required', 'integer'],
            'to_treasury_account_id' => ['required', 'integer', 'different:from_treasury_account_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->treasuryTransferService->transfer((int) $this->currentWorkspace()->id, $validated, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تنفيذ التحويل وترحيله محاسبيًا.');
    }

    public function storeStatement(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'accounting.manage');
        $validated = $request->validate([
            'treasury_account_id' => ['required', 'integer'],
            'statement_date' => ['required', 'date'],
            'opening_balance' => ['required', 'numeric'],
            'closing_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $statement = $this->bankReconciliationService->createStatement((int) $this->currentWorkspace()->id, $validated);

        return redirect()
            ->route('workspace.finance.treasury.statements.show', $statement)
            ->with('success', 'تم إنشاء كشف البنك.');
    }

    public function showStatement(Request $request, FinanceBankStatement $statement): View
    {
        $this->authorizeFinance($request, 'finance.view');
        $this->assertSameWorkspace($statement->workspace_id);

        return view('workspace.finance.treasury.statement', [
            'statement' => $statement->load(['treasuryAccount', 'lines']),
        ]);
    }

    public function storeLines(Request $request, FinanceBankStatement $statement): RedirectResponse
    {
        $this->authorizeFinance($request, 'accounting.manage');
        $this->assertSameWorkspace($statement->workspace_id);
        $validated = $request->validate([
            'lines_json' => ['required', 'string'],
        ]);
        $lines = $this->parseJsonField($request, 'lines_json');

        try {
            $this->bankReconciliationService->addLines($statement, $lines);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تمت إضافة حركات الكشف.');
    }

    public function suggest(Request $request, FinanceBankStatement $statement): RedirectResponse
    {
        $this->authorizeFinance($request, 'accounting.manage');
        $this->assertSameWorkspace($statement->workspace_id);
        $count = $this->bankReconciliationService->suggestMatches($statement);

        return back()->with('success', 'اقتراحات المطابقة: '.$count);
    }

    public function matchLine(Request $request, FinanceBankStatement $statement, FinanceBankStatementLine $line): RedirectResponse
    {
        $this->authorizeFinance($request, 'accounting.manage');
        $this->assertSameWorkspace($statement->workspace_id);
        $this->assertSameWorkspace($line->workspace_id);
        abort_unless((int) $line->bank_statement_id === (int) $statement->id, 404);

        $validated = $request->validate([
            'matched_type' => ['required', 'string'],
            'matched_id' => ['required', 'integer'],
        ]);

        try {
            $this->bankReconciliationService->matchLine(
                $line,
                $validated['matched_type'],
                (int) $validated['matched_id'],
                (int) $request->user()->id
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تمت مطابقة الحركة.');
    }

    public function complete(Request $request, FinanceBankStatement $statement): RedirectResponse
    {
        $this->authorizeFinance($request, 'accounting.manage');
        $this->assertSameWorkspace($statement->workspace_id);

        try {
            $this->bankReconciliationService->complete($statement);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إغلاق التسوية.');
    }
}
