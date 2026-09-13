<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Customer;
use App\Models\Projects\FinanceProject;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ProjectController extends FinanceBaseController
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');
        $projects = FinanceProject::query()->with('customer')->latest('id')->paginate(20);
        $profit = [];
        foreach ($projects as $project) {
            $profit[$project->id] = $this->projectService->profitability($project);
        }

        return view('workspace.finance.projects.index', [
            'projects' => $projects,
            'customers' => Customer::query()->orderBy('name')->limit(100)->get(['id', 'name']),
            'profit' => $profit,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'finance.manage');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->projectService->create($this->currentWorkspace(), $validated);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إنشاء المشروع.');
    }
}
