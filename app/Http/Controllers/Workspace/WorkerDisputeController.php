<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Worker\CreateWorkerDisputeRequest;
use App\Http\Requests\Worker\WorkerDisputeFilterRequest;
use App\Models\Worker\WorkerDispute;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerDisputeException;
use App\Services\Worker\WorkerDisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkerDisputeController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly WorkerDisputeService $disputes,
    ) {}

    public function index(WorkerDisputeFilterRequest $request): View
    {
        $this->authorize('viewAny', WorkerDispute::class);
        $workspace = $this->currentWorkspace();

        return view('workspace.worker-disputes.index', [
            'disputes' => $this->disputes->companyIndex(
                $request->user(),
                $workspace,
                $request->validated(),
            ),
        ]);
    }

    public function show(Request $request, WorkerDispute $dispute): View
    {
        $this->authorize('view', $dispute);
        $workspace = $this->currentWorkspace();

        return view('workspace.worker-disputes.show', [
            'dispute' => $this->disputes->companyShow(
                $request->user(),
                $workspace,
                $dispute->id,
            ),
        ]);
    }

    public function store(
        CreateWorkerDisputeRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        $this->authorize('create', WorkerDispute::class);

        try {
            $dispute = $this->disputes->openForCompany(
                $request->user(),
                $this->currentWorkspace(),
                $engagement,
                $request->validated(),
                $request,
            );
        } catch (WorkerDisputeException $exception) {
            return back()->withInput()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.worker-disputes.show', $dispute)
            ->with('success', 'تم تسجيل النزاع للمراجعة.');
    }
}
