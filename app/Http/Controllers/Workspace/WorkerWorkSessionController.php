<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\WorkerOperationalReportRequest;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerOperationalReportingService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\View\View;

class WorkerWorkSessionController extends Controller
{
    public function __construct(
        private readonly WorkerOperationalReportingService $reporting,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(
        WorkerOperationalReportRequest $request,
        WorkerEngagement $engagement,
    ): View {
        $this->authorize('viewSessions', $engagement);
        $workspace = $this->workspaceContext->workspace();
        abort_unless($workspace !== null, 404);

        return view('workspace.worker-engagements.sessions', $this->reporting->companySessionReport(
            $request->user(),
            $workspace,
            $engagement,
            $request->validated(),
        ));
    }
}
