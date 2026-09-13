<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Crm\CrmLead;
use App\Services\Crm\LeadService;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly LeadService $leadService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CrmLead::query()
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('company_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (CrmLead $lead) => $this->presenter->lead($lead))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:80'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $lead = $this->leadService->create($workspace, $validated);

        return $this->ok($this->presenter->lead($lead), message: 'تم إنشاء العميل المحتمل.', status: 201);
    }

    public function show(Request $request, CrmLead $lead): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.view');

        return $this->ok($this->presenter->lead($lead));
    }

    public function convert(Request $request, CrmLead $lead): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.manage');
        $customer = $this->runFinanceDomain(fn () => $this->leadService->convertToCustomer($lead));

        return $this->ok([
            'lead' => $this->presenter->lead($lead->fresh()),
            'customer' => $this->presenter->customer($customer),
        ], message: 'تم تحويل العميل المحتمل إلى عميل.');
    }

    public function markLost(Request $request, CrmLead $lead): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.manage');
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $updated = $this->runFinanceDomain(fn () => $this->leadService->markLost($lead, $validated['reason'] ?? null));

        return $this->ok($this->presenter->lead($updated), message: 'تم تعليم العميل المحتمل كضائع.');
    }
}
