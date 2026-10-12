<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\WorkerEngagementResponseRequest;
use App\Http\Requests\Worker\WorkerOperationalReportRequest;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerEngagementException;
use App\Services\Worker\WorkerEngagementService;
use App\Services\Worker\WorkerInboxService;
use App\Services\Worker\WorkerOperationalReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerEngagementController extends Controller
{
    public function __construct(
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerEngagementAuthorization $authorization,
        private readonly WorkerOperationalReportingService $reporting,
    ) {}

    public function index(WorkerOperationalReportRequest $request): View
    {
        return view('worker.engagements.index', [
            'engagements' => $this->reporting->workerEngagements(
                $request->user(),
                $request->validated(),
            ),
        ]);
    }

    public function show(WorkerOperationalReportRequest $request, int $engagement): View
    {
        $report = $this->reporting->workerOverview(
            $request->user(),
            $engagement,
            $request->validated(),
        );
        $workerEngagement = $report['engagement'];

        return view('worker.engagements.show', [
            'engagement' => $workerEngagement,
            'operationalSummary' => $report['operationalSummary'],
            'canReadInbox' => $this->authorization->workerCan(
                $request->user(),
                $workerEngagement->workspace,
                $workerEngagement,
                WorkerInboxService::READ_CAPABILITY,
            ),
        ]);
    }

    public function accept(
        WorkerEngagementResponseRequest $request,
        int $engagement,
    ): RedirectResponse {
        return $this->respond(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->accept(
                $request->user(),
                $engagement,
                $request,
            ),
            'تم قبول عرض الارتباط.',
        );
    }

    public function reject(
        WorkerEngagementResponseRequest $request,
        int $engagement,
    ): RedirectResponse {
        return $this->respond(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->reject(
                $request->user(),
                $engagement,
                $request,
            ),
            'تم رفض عرض الارتباط.',
        );
    }

    /**
     * @param  \Closure(): WorkerEngagement  $operation
     */
    private function respond(
        int $engagement,
        \Closure $operation,
        string $message,
    ): RedirectResponse {
        try {
            $operation();
        } catch (WorkerEngagementException $exception) {
            return back()->withErrors(['engagement' => $exception->getMessage()]);
        }

        return redirect()
            ->route('worker.engagements.show', $engagement)
            ->with('success', $message);
    }
}
