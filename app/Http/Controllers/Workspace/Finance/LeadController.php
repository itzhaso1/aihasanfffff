<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Crm\CrmLead;
use App\Services\Crm\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class LeadController extends FinanceBaseController
{
    public function __construct(
        private readonly LeadService $leadService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        return view('workspace.finance.leads.index', [
            'leads' => CrmLead::query()->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:80'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->leadService->create($this->currentWorkspace(), $validated);

        return back()->with('success', 'تم إنشاء العميل المحتمل.');
    }

    public function convert(Request $request, int $lead): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $model = CrmLead::withoutGlobalScopes()
            ->where('workspace_id', $this->currentWorkspace()->id)
            ->whereKey($lead)
            ->firstOrFail();
        $this->leadService->convertToCustomer($model);

        return back()->with('success', 'تم تحويل العميل المحتمل إلى عميل.');
    }

    public function markLost(Request $request, int $lead): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $model = CrmLead::withoutGlobalScopes()
            ->where('workspace_id', $this->currentWorkspace()->id)
            ->whereKey($lead)
            ->firstOrFail();

        try {
            $this->leadService->markLost($model, $request->string('reason')->toString());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تعليم العميل المحتمل كضائع.');
    }
}
