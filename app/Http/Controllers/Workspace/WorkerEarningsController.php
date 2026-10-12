<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\ApproveWorkerEarningPeriodRequest;
use App\Http\Requests\Worker\CreateWorkerEarningLineRequest;
use App\Http\Requests\Worker\CreateWorkerEarningPeriodRequest;
use App\Http\Requests\Worker\SubmitWorkerEarningPeriodRequest;
use App\Http\Requests\Worker\WorkerOperationalReportRequest;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerEarningsException;
use App\Services\Worker\WorkerEarningsService;
use App\Services\Worker\WorkerOperationalReportingService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerEarningsController extends Controller
{
    public function __construct(
        private readonly WorkerEarningsService $earnings,
        private readonly WorkerOperationalReportingService $reporting,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(
        WorkerOperationalReportRequest $request,
        WorkerEngagement $engagement,
    ): View {
        $this->authorize('viewEarnings', $engagement);
        $workspace = $this->workspaceContext->workspace();
        abort_unless($workspace !== null, 404);

        return view('workspace.worker-engagements.earnings', $this->reporting->companyEarningReport(
            $request->user(),
            $workspace,
            $engagement,
            $request->validated(),
        ) + [
            'canManage' => auth()->user()?->can('manageEarnings', $engagement) === true,
        ]);
    }

    public function storePeriod(
        CreateWorkerEarningPeriodRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        try {
            $this->earnings->createPeriod(
                $request->user(),
                $engagement,
                $request->validated('starts_at'),
                $request->validated('ends_at'),
                $request,
            );
        } catch (WorkerEarningsException $exception) {
            return back()->withErrors(['earnings' => $exception->getMessage()]);
        }

        return $this->redirectToEarnings($engagement, 'تم إنشاء فترة الأرباح.');
    }

    public function storeLine(
        CreateWorkerEarningLineRequest $request,
        WorkerEngagement $engagement,
        int $period,
    ): RedirectResponse {
        try {
            $this->earnings->createLine(
                $request->user(),
                $engagement->id,
                $period,
                $request->validated(),
                $request,
            );
        } catch (WorkerEarningsException $exception) {
            return back()->withErrors(['earnings' => $exception->getMessage()]);
        }

        return $this->redirectToEarnings($engagement, 'تمت إضافة بند الأرباح.');
    }

    public function submit(
        SubmitWorkerEarningPeriodRequest $request,
        WorkerEngagement $engagement,
        int $period,
    ): RedirectResponse {
        try {
            $this->earnings->submit(
                $request->user(),
                $engagement->id,
                $period,
                $request,
            );
        } catch (WorkerEarningsException $exception) {
            return back()->withErrors(['earnings' => $exception->getMessage()]);
        }

        return $this->redirectToEarnings($engagement, 'تم إرسال فترة الأرباح للمراجعة.');
    }

    public function approve(
        ApproveWorkerEarningPeriodRequest $request,
        WorkerEngagement $engagement,
        int $period,
    ): RedirectResponse {
        try {
            $this->earnings->approve(
                $request->user(),
                $engagement->id,
                $period,
                $request->validated('notes'),
                $request,
            );
        } catch (WorkerEarningsException $exception) {
            return back()->withErrors(['earnings' => $exception->getMessage()]);
        }

        return $this->redirectToEarnings($engagement, 'تم اعتماد فترة الأرباح.');
    }

    private function redirectToEarnings(WorkerEngagement $engagement, string $message): RedirectResponse
    {
        return redirect()
            ->route('workspace.worker-engagements.earnings.index', $engagement)
            ->with('success', $message);
    }
}
