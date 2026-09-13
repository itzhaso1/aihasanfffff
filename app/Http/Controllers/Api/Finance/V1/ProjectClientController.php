<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Projects\FinanceProject;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Projects\ProjectService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly ProjectService $projectService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = FinanceProject::query()
            ->with('customer')
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(function (FinanceProject $project) {
                return $this->presenter->project($project, $this->projectService->profitability($project));
            })->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function show(Request $request, FinanceProject $project): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.view');
        $project->load('customer');

        return $this->ok($this->presenter->project($project, $this->projectService->profitability($project)));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $project = $this->runFinanceDomain(fn () => $this->projectService->create($workspace, $validated));

        return $this->ok(
            $this->presenter->project($project->load('customer'), $this->projectService->profitability($project)),
            message: 'تم إنشاء المشروع.',
            status: 201,
        );
    }
}
