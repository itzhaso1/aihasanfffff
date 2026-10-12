<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\GrantWorkerEngagementCapabilityRequest;
use App\Http\Requests\Worker\RevokeWorkerEngagementCapabilityRequest;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerEngagementCapabilityException;
use App\Services\Worker\WorkerEngagementCapabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerEngagementCapabilityController extends Controller
{
    public function __construct(
        private readonly WorkerEngagementCapabilityService $capabilities,
    ) {}

    public function index(WorkerEngagement $engagement): View
    {
        $this->authorize('viewCapabilities', $engagement);
        $data = $this->capabilities->forCompany($engagement);

        return view('workspace.worker-engagements.capabilities', [
            'engagement' => $engagement->load('workerProfile'),
            'capabilities' => $data['capabilities'],
            'permissions' => $data['permissions'],
            'canManage' => auth()->user()?->can('manageCapabilities', $engagement) === true,
        ]);
    }

    public function store(
        GrantWorkerEngagementCapabilityRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        try {
            $this->capabilities->grant(
                $request->user(),
                $engagement,
                $request->validated()['capability_key'],
                $request,
            );
        } catch (WorkerEngagementCapabilityException $exception) {
            return back()->withErrors(['capability' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.worker-engagements.capabilities.index', $engagement)
            ->with('success', 'تم منح القدرة للارتباط.');
    }

    public function destroy(
        RevokeWorkerEngagementCapabilityRequest $request,
        WorkerEngagement $engagement,
        string $capability,
    ): RedirectResponse {
        try {
            $this->capabilities->revoke(
                $request->user(),
                $engagement,
                $request->validated()['capability_key'],
                $request,
            );
        } catch (WorkerEngagementCapabilityException $exception) {
            return back()->withErrors(['capability' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.worker-engagements.capabilities.index', $engagement)
            ->with('success', 'تم سحب القدرة من الارتباط.');
    }
}
