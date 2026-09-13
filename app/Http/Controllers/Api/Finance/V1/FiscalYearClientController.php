<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceFiscalYear;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\FiscalYearService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FiscalYearService $fiscalYearService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.fiscal_years.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = FinanceFiscalYear::query()
            ->withCount('periods')
            ->latest('start_date')
            ->paginate((int) ($validated['per_page'] ?? 12));

        return $this->ok(
            $page->getCollection()->map(fn (FinanceFiscalYear $year) => $this->presenter->fiscalYear($year))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinanceFiscalYear $fiscalYear): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.view');
        $fiscalYear->load(['periods' => fn ($query) => $query->orderBy('start_date')]);

        return $this->ok($this->presenter->fiscalYear($fiscalYear, true));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.fiscal_years.manage');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        $validated = $request->validate($this->yearRules());
        $year = $this->runFinanceDomain(fn () => $this->fiscalYearService->create($workspace, $validated));

        return $this->ok($this->presenter->fiscalYear($year->loadCount('periods')), message: 'تم إنشاء السنة المالية.', status: 201);
    }

    public function update(Request $request, FinanceFiscalYear $fiscalYear): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.manage');
        $validated = $request->validate($this->yearRules());
        $updated = $this->runFinanceDomain(fn () => $this->fiscalYearService->update($fiscalYear, $validated));

        return $this->ok($this->presenter->fiscalYear($updated->loadCount('periods')), message: 'تم تحديث السنة المالية.');
    }

    public function close(Request $request, FinanceFiscalYear $fiscalYear): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.manage');
        $updated = $this->runFinanceDomain(fn () => $this->fiscalYearService->closeYear($fiscalYear));

        return $this->ok($this->presenter->fiscalYear($updated->load(['periods'])), message: 'تم إغلاق السنة المالية.');
    }

    public function open(Request $request, FinanceFiscalYear $fiscalYear): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.manage');
        $updated = $this->runFinanceDomain(fn () => $this->fiscalYearService->openYear($fiscalYear));

        return $this->ok($this->presenter->fiscalYear($updated->load(['periods'])), message: 'تم فتح السنة المالية.');
    }

    public function generateMonthlyPeriods(Request $request, FinanceFiscalYear $fiscalYear): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.manage');
        $created = $this->runFinanceDomain(fn () => $this->fiscalYearService->generateMonthlyPeriods($fiscalYear));

        return $this->ok(
            $this->presenter->fiscalYear($fiscalYear->fresh()->load(['periods' => fn ($query) => $query->orderBy('start_date')]), true),
            message: 'تم توليد '.$created.' فترة شهرية.',
        );
    }

    public function storePeriod(Request $request, FinanceFiscalYear $fiscalYear): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:open,closed'],
        ]);
        $period = $this->runFinanceDomain(fn () => $this->fiscalYearService->addPeriod($fiscalYear, $validated));

        return $this->ok($this->presenter->accountingPeriod($period), message: 'تم إنشاء الفترة المحاسبية.', status: 201);
    }

    public function setPeriodStatus(Request $request, FinanceAccountingPeriod $period): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.fiscal_years.manage');
        $validated = $request->validate(['status' => ['required', 'in:open,closed']]);
        $updated = $this->runFinanceDomain(fn () => $this->fiscalYearService->setPeriodStatus($period, $validated['status']));

        return $this->ok($this->presenter->accountingPeriod($updated), message: 'تم تحديث حالة الفترة.');
    }

    /**
     * @return array<string, mixed>
     */
    private function yearRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:open,closed'],
        ];
    }
}
