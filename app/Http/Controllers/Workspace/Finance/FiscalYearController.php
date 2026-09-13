<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceFiscalYear;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\FiscalYearService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FiscalYearController extends FinanceBaseController
{
    public function __construct(
        private readonly FiscalYearService $fiscalYearService,
        private readonly FinanceBootstrapService $financeBootstrapService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.fiscal_years.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($this->currentWorkspace());

        $years = FinanceFiscalYear::query()
            ->withCount('periods')
            ->latest('start_date')
            ->paginate(12)
            ->withQueryString();

        $selectedYear = null;
        if ($request->filled('fiscal_year_id')) {
            $selectedYear = FinanceFiscalYear::query()
                ->with(['periods' => fn ($query) => $query->orderBy('start_date')])
                ->whereKey($request->integer('fiscal_year_id'))
                ->first();
        } elseif ($years->count() > 0) {
            $selectedYear = FinanceFiscalYear::query()
                ->with(['periods' => fn ($query) => $query->orderBy('start_date')])
                ->whereKey($years->first()->id)
                ->first();
        }

        return view('workspace.finance.modules.fiscal-years', [
            'years' => $years,
            'selectedYear' => $selectedYear,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.fiscal_years.manage');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        try {
            $year = $this->fiscalYearService->create($workspace, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.fiscal-years.index', ['fiscal_year_id' => $year->id])
            ->with('success', 'تم إنشاء السنة المالية.');
    }

    public function update(Request $request, FinanceFiscalYear $fiscalYear): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.fiscal_years.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $this->fiscalYearService->update($fiscalYear, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.fiscal-years.index', ['fiscal_year_id' => $fiscalYear->id])
            ->with('success', 'تم تحديث السنة المالية.');
    }

    public function close(FinanceFiscalYear $fiscalYear): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.fiscal_years.manage');
        try {
            $this->fiscalYearService->closeYear($fiscalYear);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إغلاق السنة المالية.');
    }

    public function open(FinanceFiscalYear $fiscalYear): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.fiscal_years.manage');
        $this->fiscalYearService->openYear($fiscalYear);

        return back()->with('success', 'تم فتح السنة المالية.');
    }

    public function generateMonthlyPeriods(FinanceFiscalYear $fiscalYear): RedirectResponse
    {
        $this->authorizeFinance(request(), 'finance.fiscal_years.manage');
        try {
            $count = $this->fiscalYearService->generateMonthlyPeriods($fiscalYear);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "تم إنشاء {$count} فترة محاسبية شهرية.");
    }

    public function storePeriod(Request $request, FinanceFiscalYear $fiscalYear): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.fiscal_years.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        try {
            $this->fiscalYearService->addPeriod($fiscalYear, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تمت إضافة الفترة المحاسبية.');
    }

    public function setPeriodStatus(Request $request, FinanceAccountingPeriod $period): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.fiscal_years.manage');
        $validated = $request->validate([
            'status' => ['required', 'in:open,closed'],
        ]);

        try {
            $this->fiscalYearService->setPeriodStatus($period, $validated['status']);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تحديث حالة الفترة المحاسبية.');
    }
}
